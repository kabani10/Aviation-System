# Architecture

## Folder structure

Everything under `app/` follows PSR-4 (`App\`) as usual, but is grouped by what it does, not by
Laravel's default type-first layout:

```
app/
  Domain/<Module>/
    Models/            Eloquent models for this module
    Actions/            One class, one use case (CreateFlightRequest, ConfirmService, ...)
    Policies/            Authorization for this module's models
    DataTransferObjects/ Typed payloads passed between layers (optional, when a plain array leaks detail)

  AI/<Capability>/
    Extractors/          e.g. RequestExtractor — reads an inbound email, returns structured data
    Recommenders/         e.g. SupplierRecommender
    Prompts/               Prompt templates, kept out of the calling code so they're reviewable on their own
    Jobs/                   Queued jobs that call the Claude API and act on the result
    Actions/                Post-processing that turns a Claude response into domain records —
                             doesn't call the API itself, kept in AI/ because it's specific to
                             that capability's workflow (e.g. CreateFlightRequestFromExtraction)
  AI/Support/              Cross-capability plumbing with no domain meaning of its own — today,
                             just ClaudeClient, the one place that knows the Messages API's HTTP shape

  Filament/Resources/<Module>/   Admin UI, grouped to match Domain
  Http/Middleware/                 Cross-cutting request concerns (tenancy, etc.)
  Support/                          Framework-agnostic helpers with no business meaning of their own
```

**Why Actions instead of fat models or fat controllers:** almost every write in this app
(confirm a service, approve a quotation, extract a flight request from an email) is a single named
business operation, not generic CRUD. An Action class (`__invoke()`, one job) is called the same way
from a Filament resource, a queued job, or a test — no logic duplicated across three places.

**Why `app/AI` is separate from `app/Domain`:** AI capabilities have a different failure mode
(a Claude API call can fail, time out, or return something the code has to validate) and a different
review concern (prompts are content, not just code). Isolating them means a change to a prompt can't
accidentally change domain logic, and vice versa.

Filament resources stay thin: they wire fields to a model and call an Action on submit. Business
rules (pricing, risk detection, what "confirmed" requires) live in `Domain`, not in the resource
class, so they're testable without booting a browser.

**One deliberate exception:** `App\Models\User` stays at Laravel's default location instead of
`app/Domain/Tenancy/Models`. Auth, Filament, and most first-party packages assume `App\Models\User`
by convention; fighting that for the sake of a tidier folder isn't worth the friction it creates
everywhere else.

## Multi-tenancy

Single database, every tenant-owned table carries `company_id`. Isolation is enforced in two layers:

1. **`App\Domain\Shared\Concerns\BelongsToCompany`** — a trait applied to every tenant-owned model.
   It registers `CompanyScope` (a global scope that filters every query to the current company) and
   fills `company_id` on create **only when the caller hasn't already set it** — e.g. `->for($company)`
   in a factory, or an explicit assignment in an Action. It's a default, not an override: earlier code
   had this unconditional, and a `CurrentCompany` left set from earlier in the same request/console
   command/test silently reassigned records to the wrong tenant. Caught by the "company A creating a
   fixture for company B" pattern in tests — if you write a test that sets `CurrentCompany` and then
   creates something for a *different* company afterward, that's exactly the case this guards.
2. **`App\Support\Tenancy\CurrentCompany`** — a request-scoped singleton holding "which company is
   this?". Set by `App\Http\Middleware\SetCurrentCompany` from the authenticated user on every web
   and Filament request.

**Middleware order matters more than it looks like it should.** `SetCurrentCompany` is appended to the
`web` group, but Laravel's `SubstituteBindings` (which resolves `{model}` route parameters — where
`CompanyScope` would apply) runs *before* anything merely appended to a group. Route-model-binding a
tenant-scoped model in a plain `routes/web.php` route was resolving unscoped until
`bootstrap/app.php` explicitly reordered it with `prependToPriorityList`. Because that's the kind of
thing a future refactor can silently reintroduce, any controller resolving a tenant-scoped model by
route parameter (`DocumentDownloadController` is the current example) also checks `company_id`
explicitly rather than trusting the binding was scoped — middleware order is defense in depth, not
the actual boundary.

**Queued jobs and console commands don't go through HTTP middleware.** Any job that touches a
tenant-owned model must set `CurrentCompany` explicitly at the top of `handle()`, from the model it
was dispatched for:

```php
app(CurrentCompany::class)->set($flightRequest->company_id);
```

There is deliberately no automatic way around `CompanyScope` — bypassing it means
`Model::withoutGlobalScope(CompanyScope::class)`, which is loud in a diff and should only appear in
genuinely cross-tenant code (a super-admin support tool, a scheduled job that runs per-company in a
loop). If you're writing that, say why in a comment next to the call.

Every module's test suite should include at least one test asserting company A cannot see company
B's records through the resource/API it just built — not just that the scope class exists in
isolation.

## Roles

Six roles, managed via `spatie/laravel-permission`, scoped per company (a user's permissions only
mean something within their own tenant): Admin, Sales, Operations, Procurement, Finance, Management.
Permission definitions live in `database/seeders/RolesAndPermissionsSeeder.php` — that seeder is the
source of truth for what each role can do, not scattered `can()` checks invented ad hoc in resources.

## Two-factor authentication

TOTP (`pragmarx/google2fa` + `bacon/bacon-qr-code` for the QR image, rendered as inline SVG — no
external network call). Available to every account (`App\Filament\Pages\TwoFactorAuthentication`,
reachable from the user menu), voluntary for all of them — **not** mandatory for Admin, reversing the
original Phase 1/2 decision at the user's explicit request once it got in the way of local testing with
no authenticator device on hand. `RequireTwoFactorForAdmins`, the middleware that used to force an
unconfirmed Admin to the setup page before reaching anything else in the panel, is gone entirely (deleted,
not just unregistered — nothing else referenced it).

One middleware remains in `AdminPanelProvider`'s `authMiddleware`:

- **`EnsureTwoFactorChallengeCompleted`** — anyone who *has* enabled 2FA (voluntarily) must still pass a
  post-login code challenge (`/two-factor-challenge`, outside the panel) once per session before
  proceeding. Filament's `Login` page calls `Auth::attempt()` directly (full session established
  immediately) — there's no "authenticated but not yet 2FA-cleared" state to hook into upstream, so
  this is enforced downstream instead, gated on `session('2fa_passed')`.

That session key is cleared on every `Illuminate\Auth\Events\Login` (see `AppServiceProvider`) —
without that, a session cookie planted before login (session fixation) would carry a stale
`2fa_passed = true` forward and let an attacker skip the challenge for whoever logs in on it.

Recovery codes are consumed on use (removed from the stored array), and 2FA state changes
(enabled/disabled/recovery code used) are written to the activity log explicitly — see below.

**Worth reconsidering later**: Admin still bypasses every permission check (`Gate::before` in
`AppServiceProvider`), so it remains the one role with no lesser-privileged fallback if the account is
compromised — the original reasoning for requiring 2FA there hasn't gone away, just deprioritized for
now. Re-enabling it is a one-line change: reinstate a `RequireTwoFactorForAdmins`-equivalent middleware in
`AdminPanelProvider` (the class itself was deleted, so it'd need rebuilding, not just uncommenting).

## Audit logging

`spatie/laravel-activitylog`, applied per-model via the `LogsActivity` trait — not on by default,
add it deliberately when a model holds data worth an audit trail (so far: `Company`, `User`).
Two things to know about this package version specifically, since its docs/examples online often
describe an older API:

- Automatic before/after diffs land in `attribute_changes` (a real column), **not** `properties`.
  `properties` is for whatever you explicitly pass via `withProperties()`.
- `getActivitylogOptions()` always specifies `logOnly([...])` with an explicit attribute list, never
  `logAll()` — this is what keeps `password` and `two_factor_secret` out of the log. If a model has
  any sensitive field, it must be absent from that list, not merely hidden from `toArray()`.

State transitions that aren't plain attribute diffs (role assigned/changed, 2FA enabled/disabled,
recovery code used, employee invited) are logged explicitly with `activity()->performedOn(...)->log(...)`
at the point they happen — see `app/Domain/Tenancy/Actions`.

## Documents & communications

Both are polymorphic and cross-cutting — `app/Domain/Documents` and `app/Domain/Communications`.
Any model can hold either by using `HasDocuments` / `HasCommunications` (Company and User do today);
a model that wants a nicer "attached to X" label than `ClassName #id` implements `displayLabel(): string`
— see `Document::subjectLabel()`. Both are company-scoped in their own right (`BelongsToCompany`), on
top of belonging to whatever they're attached to.

- **`documents`** — `App\Domain\Documents\Actions\UploadDocument` is the only place a file is written
  to disk (the private `documents` filesystem disk, local in dev, swap to S3 in prod via
  `DOCUMENTS_DISK_DRIVER`). Never served directly — always through `DocumentDownloadController`'s
  `signed` route, and that controller checks `company_id` itself rather than trusting the route
  binding (see the middleware-order note above).
- **`communications`** — `App\Domain\Communications\Actions\LogCommunication` is the single write
  path, used both for manual notes/call-summaries and by the inbound-email webhook, so every entry is
  created the same way regardless of source. `CommunicationType` (email_in/email_out/note/
  call_summary/whatsapp/system_event) is a real PHP enum, not a string column with a convention.

**Inbound email** — `App\Http\Controllers\PostmarkInboundController`, `POST
/api/webhooks/postmark/inbound/{company:slug}?token=...`. `routes/api.php` carries the `api`
middleware group, not `web` — there's no session, so `CurrentCompany` is set explicitly in the
controller (same convention as queued jobs) rather than via `SetCurrentCompany`. The shared secret
(`POSTMARK_INBOUND_SECRET`, checked with `hash_equals`) stands in for authentication, since there's no
logged-in user; the company is resolved from the URL, not from anything in the payload. Every inbound
email still lands on the `Company` itself for now (`ReceiveInboundEmail`) even though `FlightRequest`
exists as of Phase 5 — matching an email to the *right* flight needs the AI extraction phases (reading
the message, matching customer/route/dates), not just a place to put it. Attachments become
`Document`s on the `Communication`, not on the company directly, since they belong to the email.

**Standalone resources for "browse everything", shared `RelationManager`s for "this record's".**
`DocumentResource` and `CommunicationResource` show everything in the tenant regardless of what it's
attached to. `App\Filament\RelationManagers\DocumentsRelationManager` and `CommunicationsRelationManager`
are the per-record view — two classes, reused everywhere, not one pair per module. They work on any
resource because the relationship name is always `documents`/`communications` (from the `HasDocuments`
/ `HasCommunications` traits) regardless of the owning model, so the same class attaches to
`CustomerResource`, `AircraftResource`, and whatever comes next via `getRelations()`. Model-specific
`RelationManager`s (`ContactsRelationManager`, `AircraftRelationManager` under `CustomerResource`)
stay nested under their resource as usual — only Documents/Communications are generic enough to share.

## Customers & Aircraft

`App\Domain\Customers\Models\Customer` — a client of the tenant (an operator or broker), not to be
confused with `App\Domain\Tenancy\Models\Company` (the tenant itself). Has its own contacts
(`CustomerContact`, via `ContactsRelationManager`) and fleet (`Aircraft`, via `AircraftRelationManager`
on `CustomerResource`, and its own standalone `AircraftResource` for fleet-wide search across
customers). Both `Customer` and `Aircraft` carry `company_id` directly (`BelongsToCompany`) rather than
only being reachable through a join — same reasoning as `Document`/`Communication`: a direct,
independently-scoped column is what `CompanyScope` actually filters on, not a relationship path.

**First policies built on granular permissions, not role names.** `CustomerPolicy` / `AircraftPolicy` /
`CustomerContactPolicy` check `$user->can('customers.view')` / `can('customers.manage')` — permissions
already defined in `RolesAndPermissionsSeeder` (Sales has both) rather than `hasRole('Sales')` directly.
Prefer this pattern for new modules: check the permission, not the role, so a future "give Finance
read-only customer access" is a one-line seeder change, not a policy rewrite.

**Known gap, not an oversight:** only Sales currently has `customers.*`. Operations and Management have
no permission to view customer records at all under the current seeder, which will matter once Flight
Request needs to show operators the customer behind a flight — worth revisiting when that module is
built, not fixed unilaterally here.

`Customer::preferredSuppliers()` (`customer_preferred_supplier` pivot) closes the gap flagged when this
section was written in Phase 3 — see Suppliers below.

## Suppliers & reference data

**`Country` and `Airport`** (`app/Domain/ReferenceData`) are shared across every tenant — deliberately
*not* `BelongsToCompany`. There's no Filament resource for either in the tenant panel: since they're
genuinely global, giving any tenant's Admin a CRUD screen for them would let one company's edits corrupt
what every other tenant reads. They're seeder-managed (`ReferenceDataSeeder`) and exposed read-only
wherever a picker is needed (`Supplier`'s "airports covered"). If a platform-operator admin panel is
ever built, that's where Country/Airport CRUD belongs — not here.

**As of Phase 22, `Airport` is a near-complete global import (~10k airports, ~249 countries), not a
hand-picked list.** The original ~39-airport seed only covered major business-aviation hubs; a real
inbound email addressed to Budapest/Bratislava (LHBP/LZIB) failed to auto-create a flight request purely
because those airports weren't seeded, not because of anything wrong with the extraction. `database/data/
{countries,airports}.csv` are a filtered, bundled snapshot of the public-domain OurAirports dataset (see
`database/data/README.md` for the filter and how to refresh it) — bundled rather than fetched live, since
airports rarely change and a runtime API call would add latency/cost/a failure mode to every extraction
for no benefit. `ReferenceDataSeeder` bulk-`upsert`s from these files (chunked, raw query builder, not
per-row `Eloquent::create()` — at ~10k rows that overhead is real). Every Filament `Select` over `Airport`
had to drop `->preload()` in favor of `->searchable()` (`->relationship()` fields) or an explicit
`->getSearchResultsUsing()` (plain `->options()` fields, see `FlightRequestResource::searchAirports()`) —
preloading ~10k rows into a page's Alpine state was adding roughly 1MB per field to page weight, exactly
the kind of load-time regression this larger dataset needs to avoid causing, not just be the trigger for.

**Seeded once per test run, not once per test.** `tests/Pest.php` used to reseed roles/permissions and
reference data in `beforeEach` for every Feature test — fine at 39 airports, not at 10k. `RefreshDatabase`
runs `migrate:fresh` (optionally `--seeder=X`) exactly once per test run, strictly before the per-test
transaction that wraps (and rolls back) each individual test begins; anything seeded during that one-time
step is committed outside every test's transaction and stays visible for the whole run without being
re-inserted. `Tests\TestCase` now sets `$seed = true` / `$seeder = DatabaseSeeder::class` to use exactly
that mechanism, and `Pest.php`'s `beforeEach` no longer seeds anything itself. This only works because
nothing in the suite mutates `Country`/`Airport` rows — if a test ever needs to, it should create its own
via the factories, not rely on the shared seeded set staying exactly as seeded.

**`App\Domain\Suppliers\Models\Supplier`** — a vendor the tenant works with (ground handling, fuel,
permits, ...), same shape as `Customer`: its own contacts (`SupplierContact` via `ContactsRelationManager`),
`HasDocuments` (certificates), `HasCommunications`. Two fields worth knowing the reasoning on:

- **`services_offered`** — a JSON array of `App\Domain\Shared\Enums\ServiceType` values, not a pivot
  table. The vocabulary (ground handling, fuel, landing permit, ...) is fixed and has no attributes of
  its own, so tagging is simpler than a join. `ServiceType` itself lives in `Domain/Shared` rather than
  under Suppliers because Phase 6's Service Management needs the exact same vocabulary for what a
  flight request needs — defining it once now avoids two competing lists later.
- **`airports`** — a real `BelongsToMany` to `Airport`, unlike `services_offered`, because Airport is an
  actual entity (ICAO/IATA codes, country) worth joining to rather than tagging.

**"Average response time" and "previous prices" from the original spec are modeled as of Phase 8** —
see `ComputeSupplierPerformance` under AI request extraction below. They were deliberately deferred
until now: both are computed from real supplier interactions (quotes sent, responses received) that
didn't exist until Service Management (Phase 6) produced a place to record a cost, and Phase 8's
supplier quote workflow produced the timestamps. A static rating field before either existed would
have been a number nobody updates. **Still not modeled:** "service quality" — there's no single metric
in the data for that yet (it's closer to a subjective rating than something derivable), and `notes`
(freeform) continues to cover "previous operational problems" for now, since that's genuinely just
something procurement writes down.

`SupplierPolicy` / `SupplierContactPolicy` consume `suppliers.view` / `suppliers.manage` — permissions
that already existed in `RolesAndPermissionsSeeder` since Phase 1 (Operations and Management view-only,
Procurement both); this is the first module to actually use them, same permission-over-role pattern as
`CustomerPolicy`.

## Flight Requests

`App\Domain\FlightRequests\Models\FlightRequest` is the central record from the original spec —
customer, aircraft, passenger/crew counts, `FlightStatus`, assigned employees (`assignedUsers`, a
plain `BelongsToMany` to `User`), plus `HasDocuments`/`HasCommunications` — this is what those two
modules were built for. Route and timing live on `FlightLeg` instead, not directly on this model —
see "Flight legs" below. `FlightStatus` mirrors the spec's Step 11 lifecycle with one addition:
`Cancelled`, which the spec covers for individual services but never states for the flight itself —
an omission, not a deliberate scope line, so it's included.

**Flight-level costs/selling prices are modeled as of Phase 10** — via `Quotation`, not a column on this
model; see Quotation below for why a flight can have several totals over time rather than one. Invoicing
and cross-flight financial reporting are Finance's job (Phase 12) — see Finance below. Per-service
cost/selling price exist as of Phase 6 — see Service Management below. `requested_services_summary`
stays even after a flight has real `Service` records: it's the customer's original ask in their own
words, not something structured data replaces.

**The dependent-select's filtering is a UI convenience, not the boundary — same principle as the
`documents.download` check.** `aircraft_id`'s options are filtered to the selected customer's own
fleet, but a submitted `aircraft_id` that doesn't actually belong to `customer_id` is rejected by an
explicit closure rule on the field server-side (`FlightRequestResource::form()`), not trusted from the
filtered list. Covered by a test that submits the mismatch directly, bypassing the UI filtering
entirely.

**Sales gained `flights.manage` in this phase** (previously view-only) — the original spec states
requests are entered manually "by a sales or operations employee"; the Phase 1 seeder, written before
Flight Request existed to check permissions against, only gave Operations the ability to create one.
Fixed here, not silently — see the comment in `RolesAndPermissionsSeeder`. `customers.*` still has the
same Operations/Management gap noted in Customers & Aircraft above; that one's still open.

**A genuine Laravel factory bug, not application code:** `FlightRequestFactory` can't use
`'customer_id' => Customer::factory()` / `'aircraft_id' => Aircraft::factory()` as lazy nested-factory
values the way every other factory in this codebase does. Because `Aircraft`'s own factory *also*
nests a `Customer::factory()`, having two co-dependent nested factories of the same related model in
one `definition()` trips something in Laravel's factory relationship-recycling — `parentResolvers()`
ends up with a `BelongsToRelationship` holding an empty relationship name and a null factory, and the
empty-string method call blows up as `BadMethodCallException: Call to undefined method
Illuminate\Database\Query\Builder::()`. Reproduced with a minimal anonymous `Factory` subclass outside
the app's own classes, so it isn't a bug in `BelongsToCompany` or our models. The workaround:
`FlightRequestFactory::definition()` creates the customer and a matching aircraft *eagerly* (real
`->create()` calls, not lazy factory values), and `configure()`'s `afterMaking` re-points `aircraft_id`
if a `->for($customer)` override afterward left the pair mismatched. If a future factory needs two
nested factories of the same model, know this exists before spending an hour re-discovering it.

## Flight legs

`App\Domain\FlightRequests\Models\FlightLeg` is one origin-to-destination hop of a `FlightRequest` —
`sequence`, `originAirport`/`destinationAirport`, `departure_at`/`arrival_at`. A one-way flight is a
`FlightRequest` with a single leg, not a separate code path: `FlightRequestFactory` always creates
one in `afterCreating`, `CreateFlightRequestFromExtraction` always creates one alongside the flight
it builds, and `CreateFlightRequest` (the Filament create page) collects one leg's worth of route
fields inline and splits them into a `FlightLeg` on save. There is no "flight with no legs" state
anywhere in the app.

**Why legs exist at all, not just a route on the flight:** a real trip can be multi-stop —
DXB-IST then IST-CDG — and ground handling at the Istanbul stopover is a different supplier, cost,
and confirmation than at the Paris destination. Flattening that onto one services list per flight
would hide exactly the distinction an operator needs to see. `FlightRequestResource` gets a **Legs**
tab (`LegsRelationManager`) for managing the itinerary — adding a second leg, fixing a mistake in an
existing one. Editing an existing flight's route happens there, not on the flight's own edit form,
which only shows route fields while *creating* (`->visible(fn (string $operation) => $operation ===
'create')`) — on edit, "which leg" is ambiguous once more than one exists, so it isn't offered inline
at all.

**`Service` belongs to a `FlightLeg`, not directly to the `FlightRequest`, but keeps
`flight_request_id` too — a deliberate, documented denormalization, not a drift-prone copy of a
computed value the way this codebase normally avoids.** `flight_leg_id` is set once at creation and
never reassigned, so it can't go stale the way a cached total could. Every check and
quotation-generation query that cares about "this flight's services"
(`CheckMissingInformation`, `CheckOperationalRisks`, `CheckFlightReadiness`,
`CreateQuotationFromServices`, `ServicesRelationManager`'s `$relationship = 'services'`) reads
`FlightRequest::services()` — a plain `HasMany` via `flight_request_id`, unchanged by this phase —
rather than joining through legs. Keeping that column is what let all of that code, and every
existing test that builds a `Service` via `->for($flightRequest)`, keep working unmodified. The
`ServicesRelationManager` create form adds one more required field, `flight_leg_id` (a `Select` of
this flight's own legs, validated server-side the same "options list isn't the boundary" way
`aircraft_id` is), plus a `Leg` column and filter on the table — that's the actual "operator sees
each leg's own requirements" surface.

**`ServiceFactory` and `FlightRequestFactory` both auto-resolve a leg so existing call sites didn't
need touching.** `Service::factory()->for($flightRequest)` — the pattern used everywhere already —
reuses the flight's first leg if one exists, or creates one, entirely inside the factory's
`afterMaking` hook. A caller that genuinely wants a service on a *specific* leg passes
`flight_leg_id` explicitly and that wins instead.

**Deleting a leg is allowed** (`FlightLegPolicy`), unlike the "no hard delete" convention for
Service/Customer/etc — a leg is structural, correctable data, not a business record with its own
history. `LegsRelationManager` still refuses to delete the last remaining leg, or one that already
has services on it (which would otherwise be silently orphaned) — enforced in the relation manager's
`DeleteAction` visibility, not the policy, since it's about the state of *this* record, not a
blanket permission.

**`FlightRequest::displayLabel()`/`routeLabel()` chain every leg's airports into one string** —
`"KJFK-EGLL"` for a one-way flight (identical output to before legs existed), `"DXB-IST-CDG"` for a
two-leg trip. Doesn't assume legs are contiguous; a leg's destination not matching the next leg's
origin still produces a readable chain, just a longer one. The flight list's Departure column sorts
by the *earliest* leg's departure (`withMin('legs', 'departure_at')`, a real aggregated column, not
computed per row) — a flight's own "departure" is its first leg's, for a multi-leg trip.

**AI request extraction parses every leg an email describes, not just the first** — `legs` in
`RequestExtractionPrompt::tool()` is an array, not a single origin/destination pair, and
`CreateFlightRequestFromExtraction::resolveLegs()` resolves each one to a real `Airport` pair before
creating any `FlightLeg` rows. One unresolved *route* — a code the model wasn't confident about, no
destination at all — fails the *whole* extraction, same all-or-nothing reasoning as an unmatched
customer or aircraft: a flight silently missing its second leg's route isn't a draft worth
auto-creating, so it falls back to the stashed-metadata path instead like any other low-confidence
extraction. `ExtractedFlightRequest::$legs` is `ExtractedFlightLeg[]`, not a raw array, for the same
reason the rest of this DTO is typed rather than passing `$input` straight through.

**A leg's departure/arrival times are deliberately *not* part of that all-or-nothing gate — only the
route is.** `flight_legs.departure_at`/`arrival_at` are nullable (see the
`make_flight_legs_departure_and_arrival_nullable` migration); an email that clearly identifies the
customer, aircraft, and route but only says "departing tomorrow" with no arrival time at all is still
a real, actionable request worth creating, just one with a gap — not something to silently park as an
unlinked `Communication` the way a genuinely unresolvable request is. `resolveLegs()` still rejects a
leg whose *explicit* dates don't make sense (arrival not after departure — that's wrong, not missing),
but a null/unparseable time is left null on the `FlightLeg` and picked up by `CheckMissingInformation`
instead (`legs.{id}.departure_at`/`arrival_at` findings), the same way a missing passenger count
already is. The itinerary widget and `LegsRelationManager` table both render a null time as "TBD"
rather than erroring.

This gate found a real gap during manual testing: the prompt had no way to resolve "tomorrow" into an
actual date, so the model either correctly left it null or — inconsistently, across otherwise-identical
calls — guessed a plausible-looking but *wrong* one (a past year, nowhere near the email's real send
date). `RequestExtractionPrompt::userContent()` now takes a `referenceDate` (`RequestExtractor` passes
`$communication->occurred_at`, the email's actual sent date, not `now()` — this must resolve correctly
even when the extraction job runs long after the email arrived) and the system prompt is explicit:
resolve relative dates against it, but if there's no usable time reference in the email at all, leave
the field null rather than inventing one — "leaving it null is always safer than a wrong flight date."

**Each leg's guessed `service_types` become real, draft `Service` rows — status `NotStarted`, no
supplier or price, exactly what an operator adding one by hand leaves blank.** This is a deliberate
step past "just extract what's stated": the prompt tells Claude to *guess* which services a leg
needs (ground handling by default for any leg that actually lands somewhere; fuel/permits/catering/
etc. only when the email or the nature of the trip implies them), not only transcribe an explicit
list. Unlike every other extracted field, an empty or unrecognized `service_types` never affects
confidence — `CreateFlightRequestFromExtraction::resolveServiceTypes()` just filters out anything
that doesn't map to a real `ServiceType` case (`ServiceType::tryFrom()`, not `from()` — a model
hallucinating a category it wasn't given shouldn't be able to throw) and creates however many
services survive, including zero. A flight with a route the model is confident about but no sensible
service guess is still worth auto-creating; a plausible-looking but nonsense service type is worth
silently dropping, not worth failing the extraction over.

**Not built:** `FlightLeg` has no `LogsActivity` (same as `Service`, which sits at the same
granularity) and no document/communication attachment of its own.

**Two read-only additions make "which leg needs what" visible without clicking into a filtered
table:** `FlightItineraryOverview` (a page widget, not a `RelationManager` — it needs no create/edit
capability of its own, both tabs below it already cover that) renders every leg as its own section
with that leg's services inline, on both `ViewFlightRequest` and `EditFlightRequest`. It isn't in
`app/Filament/Widgets` — that folder is auto-discovered onto the Dashboard (see
`AdminPanelProvider`), and this widget requires a specific `FlightRequest` record it has no business
rendering without; it's wired in explicitly via each page's `getHeaderWidgets()` instead, which
Filament auto-passes the current `$record` to (any widget with a public `$record` property gets it
for free — see `InteractsWithRecord::getWidgetData()`). It also disables Filament's default
lazy-loading (`$isLazy = false`) — a widget that only loads once scrolled into view is fine for a
dashboard chart, wrong for the main point of this particular page.

A slide-out **Mailpit panel**, registered as a scoped render hook
(`AdminPanelProvider::renderHook(PanelsRenderHook::BODY_END, ..., scopes: [ViewFlightRequest::class,
EditFlightRequest::class])`), embeds the local Mailpit inbox in an iframe so an operator can check
what actually got sent for this flight without leaving the page or alt-tabbing to a separate
`localhost:8025` tab. Reads `config('services.mailpit.url')` (`MAILPIT_URL` in `.env`,
`http://127.0.0.1:8025` locally) — the whole panel doesn't render at all when that's unset, rather
than pointing an iframe at a Mailpit that doesn't exist outside local dev. Deliberately shows the
*entire* inbox, not just this flight's own emails (`Communication`'s `EmailOut` entries already cover
that, on the Communications tab) — Mailpit is the "did this actually leave the app correctly" check,
a different question than "what's the correspondence history for this flight."

**Filament panels serve their own prebuilt CSS bundle — they never re-scan the app's own Blade
partials for Tailwind classes.** This surfaced as a real bug: the Mailpit panel's positioning classes
(`right-0`, `fixed`, etc.) simply didn't exist in any stylesheet the panel actually loaded, so the
panel rendered in the wrong place in a real browser despite every Pest test passing — those tests only
assert on HTML text/structure, not computed CSS, and this project's own `resources/css/app.css` (the
Tailwind entry that scans `resources/views/**`) is wired into `welcome.blade.php` only, never into the
`admin` panel. `resources/css/filament-extras.css` is a second, tiny entry point — Tailwind v4's
`theme.css`/`utilities.css` layers only, deliberately skipping `preflight.css` so it can't reset
Filament's own base element styles — scoped via `@source` to `resources/views/filament`, loaded
panel-wide through a `HEAD_END` render hook in `AdminPanelProvider`. Registered in `vite.config.js`'s
`input` array alongside the app's main entry. Any future custom Filament view (a widget, another
render-hook partial) that reaches for a Tailwind utility not already used by Filament's own UI needs
nothing extra — it's covered by this same stylesheet, not a per-view opt-in.

## Service Management

`App\Domain\Services\Models\Service` — one line item on a flight (ground handling, fuel, a landing
permit), `belongsTo FlightRequest` and, as of "Flight legs" above, `belongsTo FlightLeg` too —
`flight_request_id` is kept as a documented denormalization so this section's description of
`Service` still holds; see that section for why. `BelongsToCompany` directly (same "a join isn't
what CompanyScope filters on" reasoning as everywhere else). No standalone `ServiceResource`: unlike
Documents/
Communications, there's no real "browse every service across every flight" use case in the spec —
services only make sense in the context of the flight they're on, so `ServicesRelationManager` nested
under `FlightRequestResource` is the only UI. `ServiceStatus` mirrors the same Step 11 source as
`FlightStatus`; `ServiceType` is the enum already built in Phase 4 for `Supplier.services_offered` —
same vocabulary, one definition.

**`cost` / `selling_price` are real fields now, not deferred like flight-level pricing** — the original
spec lists them as core per-service attributes, and without them "know whether a flight is profitable"
(the spec's stated value prop) is meaningless. `profitMargin()` is computed from the two on read, never
stored — a third column would just be a copy that can drift.

**Field-level permission gating, not just screen-level.** The spec draws the line at the field, not the
page: *"Sales may see selling prices but not necessarily all supplier costs."* `cost` and
`selling_price` each check `finance.view_costs` / `finance.view_prices` independently via both
`->visible()` **and** `->dehydrated()` on the form fields, and `->visible()` on the table columns. The
`dehydrated()` half is the part that's easy to skip and dangerous to: a merely-hidden field can still
submit its last-known value (or `null`) with the rest of the form, so a non-Finance user saving an
unrelated field (say, `notes`) could otherwise silently wipe an existing cost. Test this pattern by
forcing the gated value into `fillForm()`/`callTableAction()` data directly and asserting the database
row is unaffected — asserting the field merely "looks hidden" doesn't catch a dehydration bug.

**Two more spec-supported permission fixes, same reasoning as Sales/`flights.manage` in Phase 5:**
Sales gained `services.view` (selling price lives on `Service`, so price visibility needs a screen to
attach to) and Finance gained `services.view` (its whole job per the spec — "supplier costs,
profitability" — otherwise had nowhere to read a cost from despite holding `finance.view_costs`). Both
documented inline in `RolesAndPermissionsSeeder`.

**Supplier filtering is a shortlist, not a rule.** `supplier_id`'s options narrow to suppliers whose
`services_offered` contains the selected `type` (`whereJsonContains`), but nothing stops picking a
supplier outside that list — unlike `aircraft_id`/`customer_id` in Flight Requests, a service using an
"unlisted" supplier isn't invalid data, just unusual, so there's no server-side rule enforcing it.

**Phase 14 grouped `ServicesRelationManager`'s table by leg, on by default (`->groups([Group::make('flight_leg_id')...])->defaultGroup('flight_leg_id')`).**
Before this, "which leg does this service belong to" meant scanning a flat table's Leg column or reaching
for its filter — the workflow gap this closes is "for each leg, multiple services, the operator should be
able to go to the service of a leg" (the leg-scoped read-only `FlightItineraryOverview` widget already
covered *viewing* this; this is the *editable* Services tab catching up to the same clarity). The group
title comes from `FlightLeg::displayLabel()` via `getTitleFromRecordUsing`; the raw "Leg" column stays
in the table too, for whenever an operator switches Filament's "Group by" control back to "None". Groups
sort by the raw `flight_leg_id` — no `orderQueryUsing` override — which comes out leg-sequence order in
practice, since every creation path (`CreateFlightRequestFromExtraction`, `CreateFlightRequest`,
`LegsRelationManager`) always creates legs in ascending sequence order already.

**"Operational risks" from the spec is modeled as of Phase 8** — `CheckOperationalRisks`, reading
deadlines, statuses, and quote-request timestamps across a flight's services; see AI request
extraction below for why it's deterministic domain code, not an `AI/*` class, despite the spec calling
it "AI Risk Detection". `HasDocuments` is still on the `Service` model (a service's own permit/
certificate belongs here, not on the flight) with no dedicated document UI: Filament doesn't nest a
`RelationManager` inside another `RelationManager`, and `Service` has no top-level resource of its own
to hang one off.

**A `ViewRecord` page is required wherever view-only roles need to reach a `RelationManager`, not just
`ListRecords`/`EditRecord`.** Filament's `EditRecord` page requires *update* rights by default — a role
with only `flights.view` (Procurement, Finance, Management) couldn't open a flight request at all before
this phase, since the only "detail" route was `/edit`. That silently blocked those roles from
`ServicesRelationManager`/`DocumentsRelationManager`/`CommunicationsRelationManager` too — discovered
via a Service test that actually exercised a view-only role, not something visible from the code. Fixed
by adding a `ViewRecord` page (gated on the `view` policy ability, not `update`) plus a `ViewAction` in
the table, to every resource with nested `RelationManager`s where a view-only role exists:
`FlightRequestResource`, `SupplierResource`, `CustomerResource`. Resources without child relation
managers (`AircraftResource`, `UserResource`) don't need one — the list/edit pages are the whole story
there. Give any *future* resource with relation managers a `ViewRecord` page from the start rather than
waiting to rediscover this.

## AI request extraction

The first phase that actually calls the Claude API — the spec's "AI Request Extraction" and
"AI Missing-Information Detection" features. Only one of those two turned out to need an LLM;
see the missing-information note below for why the other is plain domain code.

**`App\AI\Support\ClaudeClient`** is the only class in the codebase that knows the Messages API's
HTTP shape — a thin wrapper over `Http::post()`, bound as a singleton in `AppServiceProvider` from
`config('services.anthropic.*')` (`ANTHROPIC_API_KEY` / `ANTHROPIC_MODEL`, default `claude-opus-5`).
Everything else calls through it, per the "don't call the Claude API directly" rule below. It's
mocked in tests via `Http::fake(['api.anthropic.com/*' => ...])`, not by faking the client itself,
so a test failure that's really an HTTP-shape mismatch shows up as one.

**Deliberately not forcing `tool_choice`.** The obvious way to get structured output from a tool-use
call is `tool_choice: {type: "tool", name: "..."}`, but Claude Opus 5 has thinking on by default, and
disabling thinking to safely combine with a forced tool can make the model write the tool call into
plain text instead of an actual `tool_use` block — exactly the failure mode a structured-extraction
caller can't tolerate. `ClaudeClient::messages()` leaves `tool_choice` on the default `auto`, offers
exactly one tool, and instructs the system prompt to always call it; `RequestExtractor` treats a
missing `tool_use` block as a `ClaudeApiException`, not something to unwrap and hope for.

**`App\AI\RequestExtraction`** is the one capability here so far:

- **`RequestExtractionPrompt`** builds the system prompt, the `extract_flight_request` tool schema,
  and the user content — the inbound email plus the tenant's own customers and their aircraft
  (id, name, billing email, registration), so Claude matches the sender against real records and
  returns actual database ids rather than names for the app to fuzzy-match afterward. Capped at 200
  customers serialized per call; revisit if a tenant's customer list grows past that.
- **`RequestExtractor`** calls `ClaudeClient` and turns the tool's `input` into an
  `ExtractedFlightRequest` DTO, whose `legs` is an `ExtractedFlightLeg[]` — almost always one entry,
  more only for a genuine multi-stop itinerary (see "Flight legs" above). Airport fields stay as
  ICAO/IATA code *strings* here — resolving a code to an `Airport` row is a deterministic lookup, not
  something worth asking the model to do.
- **`ExtractFlightRequestFromEmail`** (a queued job) sets `CurrentCompany` explicitly (same convention
  as every other job — see Multi-tenancy above), calls the extractor, and hands the result to
  `CreateFlightRequestFromExtraction`. A `ClaudeApiException` — no API key configured, Claude declined,
  a network error — is logged and swallowed: the inbound Communication still exists either way, it
  just doesn't become a draft automatically. This is also why `ANTHROPIC_API_KEY` being blank (the
  `.env.example`/CI default) doesn't break anything — extraction silently no-ops instead of failing
  the request that logged the email in the first place.
- **`CreateFlightRequestFromExtraction`** is the confidence gate — **as of Phase 22, only
  `resolveLegs()` resolving every extracted leg is required**, not the customer/aircraft too. One bad
  leg still fails the whole extraction (both airport codes must match a real `Airport`, departure/arrival
  both parsed, arrival after departure — for every single leg, not just the first; there's no such thing
  as a `FlightRequest` created with some legs missing), since airports are shared reference data an
  operator can't spin up inline the way a customer or aircraft can. Customer and aircraft are each
  independently optional: an unmatched one is simply left `null` on the created `FlightRequest`
  (`customer_id`/`aircraft_id` are nullable columns as of Phase 22) rather than blocking creation
  entirely. If both resolved but the aircraft doesn't belong to the resolved customer, *both* are
  dropped to `null` — a wrong pairing is worse than an admittedly-missing one. Whenever legs resolve, the
  `FlightRequest` is created (`source: Email`, `reviewed_at: null`, `extraction_metadata` holding the raw
  tool input for later "why did it fill this in this way" debugging) and the Communication is **always**
  moved onto it — `communicable_type`/`communicable_id` aren't in `Communication`'s `#[Fillable]` list, so
  this is a direct property assignment + `save()`, not `update()`. This is the "matching an email to the
  right flight" step that the Documents & communications section above flagged as blocked until this
  phase existed. Only when there are no legs at all (or none resolve) is nothing created: the raw
  extraction is stashed on the Communication's own `metadata['ai_extraction']`, and the Communication
  stays exactly where `ReceiveInboundEmail` put it (on the `Company`).

  **Why this changed from the original all-or-nothing gate:** a real inbound email (a genuine new-customer
  charter request, route and dates all clear) was silently going nowhere because the sender wasn't yet a
  known `Customer` — the extraction was thrown away entirely and the email sat invisible on the Company
  with no signal to any operator that it existed. `CheckMissingInformation` (below) now flags a `null`
  `customer_id`/`aircraft_id` the same way it flags a missing passenger count, and
  `FlightRequestResource`'s `customer_id`/`aircraft_id` `Select`s carry `->createOptionForm()` (the
  aircraft one also needs a custom `->createOptionUsing()`/`->createOptionAction()` since it's a plain
  `->options()` field scoped by the currently-selected customer, not a `->relationship()` one) so an
  operator resolves either gap in place, from the review page, instead of bouncing to `CustomerResource`/
  `AircraftResource` and back.

**`RequestSource` and `needsReview()`.** `FlightRequest.source` is `manual` (the DB default, every
existing creation path) or `email`. `needsReview(): bool` is `source === Email && reviewed_at === null`
— this is the spec's Step 3 ("operator reviews the AI draft... approves or corrects"), not a new
`FlightStatus`; an AI draft is a perfectly normal `NewRequest` that additionally needs a human to look
at it once. The Filament table shows a warning badge ("AI draft — needs review") in place of the
normal source label while that's true, and both `ViewFlightRequest`/`EditFlightRequest` (via the
shared `HasFlightRequestReviewActions` trait — Filament resource pages don't share a common ancestor
worth putting this on instead; renamed from `HasAiReviewActions` in Phase 8 once it grew a second
non-AI action, see below) expose a "Mark AI draft reviewed" header action that only appears while
`needsReview()` is true. Confirming it also assigns the confirming user
(`$record->assignedUsers()->syncWithoutDetaching([Auth::id()])`) — the moment an AI draft is reviewed is
the moment it becomes someone's flight request to work, not just a `NewRequest` sitting unowned in the
list. `syncWithoutDetaching` rather than a plain `attach`/`sync` so this can never drop an existing
assignment, even though nothing currently re-runs it after the first confirm (`needsReview()` hides the
action once `reviewed_at` is set).

**Phase 13 gave the spec's Step 3 its own screen — `ReviewFlightRequest`, reached via a "Review draft"
row action on the list that only appears while `needsReview()` is true.** Before this, confirming or
correcting an AI draft meant opening the plain `EditFlightRequest` form with no way to see the email it
came from without a detour through the Communications tab or Mailpit. `ReviewFlightRequest extends
EditRecord` (same base as `EditFlightRequest`, same `flights.manage` gate, same `HasFlightRequestReviewActions`
trait for "Mark AI draft reviewed") but swaps in a custom `$view`
(`filament.flight-requests.pages.review-ai-draft`) that renders the ordinary form next to a read-only
panel showing the source email — subject, sender, body, attachments. Saving corrections and confirming
the draft are deliberately still two separate actions, the plain form Save button and "Mark AI draft
reviewed", not a new combined one — this page behaves exactly like `EditFlightRequest` once open, it
just adds the email alongside it.

**The "source email" is the earliest `EmailIn` `Communication` on the flight, not the latest.**
`CreateFlightRequestFromExtraction` moves the triggering email onto the flight at creation time, but by
the time an operator gets to reviewing it the flight may have picked up a reply or two — `getSourceEmail()`
explicitly reorders past `HasCommunications`' own `->latest('occurred_at')` default (`->reorder('occurred_at')`,
not `->oldest()` stacked on top of it, which would just add a second, losing `ORDER BY` clause) rather than
showing whatever came in most recently. The kanban board's cards link an unreviewed flight's card to this
page instead of the plain view page too, for the same reason the list's row action exists — same
`needsReview()` check, `resources/views/filament/flight-requests/kanban-board.blade.php`.

**`App\Domain\FlightRequests\Actions\CheckMissingInformation` is plain domain code, not `AI/*`** —
a deliberate scope call, not an oversight. Every check the spec asks for (missing passenger/crew
count, no customer billing email, an expired aircraft document, a landing/overflight permit service
with no supporting documents, a service with no supplier assigned) is a deterministic lookup with no
ambiguity for an LLM to resolve. `app/AI` exists to isolate a specific failure mode — a Claude API
call can fail, time out, or return something to validate — and there's no such failure mode here, so
routing it through `AI/*` would just be following the spec's feature *name* instead of what the
feature actually *needs*. Computed on demand rather than stored, since the answer changes as an
operator fills in gaps — a stored result would go stale the moment someone adds a passenger count.

**Phase 22 added `customer_id`/`aircraft_id` findings**, alongside (not instead of) the existing
billing-email check — a missing customer and a customer with no billing email are different problems
with different fixes, so a `null` `customer_id` short-circuits the billing-email check rather than
firing both ("no customer" already implies "no billing email", saying both is just noise).

**Neither `CheckMissingInformation` nor `CheckOperationalRisks` has a header action on the flight
request page anymore** — both originally had one (a button opening a modal listing findings, via
`HasFlightRequestReviewActions`), removed at the user's request since the daily digest
(`BuildFlightRequestDigest`, see "Notifications & reminders" below) already surfaces the same findings
without needing a per-flight click. Both domain actions are unchanged and still run there — only the
on-page button and its modal view are gone; `flightRequestReviewHeaderActions()` now returns just
"Mark AI draft reviewed".

**Not modeled:** the spec's "insufficient time to obtain a permit" check. That needs a per-country
permit lead-time model that doesn't exist yet — Suppliers & reference data already deferred
permit-specific rules for the same reason; this is the same gap surfacing again, not a new one.

## Supplier quotes and AI supplier recommendation

Phase 8 operationalizes the `SupplierRequestSent`/`QuotationReceived` statuses that existed on
`ServiceStatus` since Phase 6 with no action behind them, and uses the data that produces to close two
gaps flagged as deliberately deferred in earlier phases: Suppliers' "average response time"/"previous
prices" (Phase 4) and Service Management's "operational risks" (Phase 6).

**The quote request/response cycle.** `Service` gained two nullable timestamps, `quote_requested_at`
and `quote_received_at`, plus `HasCommunications` (see Documents & communications above — Service is
now the sixth model with a timeline). Two Actions drive them:

- **`SendSupplierRequest`** emails a `SupplierContact` via `App\Mail\SupplierQuoteRequestMail` — the
  first outbound Mailable in the app; everything before this was inbound-only (Postmark). It logs the
  send as a `Communication` (`EmailOut`) on the `Service` itself, sets `quote_requested_at`, and moves
  status to `SupplierRequestSent`. The email deliberately omits `selling_price` — the supplier needs to
  know what's being asked of them, not what the customer is being charged.
- **`RecordSupplierQuote`** sets `cost`, `quote_received_at`, moves status to `QuotationReceived`, and
  logs the response as a `Communication` (`EmailIn`) — whether the quote actually arrived by email or
  was recorded from a phone call, the timeline records it as received either way.

Both are exposed as row actions on `ServicesRelationManager`, not folded into the existing edit form:
they're one-shot events with their own side effects (an email sent, a timeline entry, a status
transition), not just field edits.

**`App\Domain\Suppliers\Actions\ComputeSupplierPerformance`** turns `quote_requested_at`/
`quote_received_at`/`cost` history into `servicesCount`, `averageResponseTimeHours`, `averageCost`,
`confirmedCount`, and `atRiskOrCancelledCount` for one supplier (optionally scoped to a `ServiceType`).
Deliberately deterministic — plain averages and counts, nothing an LLM should be computing — and
consumed by `SupplierRecommender` below rather than shown as some standalone "supplier score" screen,
since a bare number without the reasoning behind it isn't much more useful than the "static rating
field nobody updates" this was deferred to avoid in the first place.

**`App\AI\SupplierRecommendation\Recommenders\SupplierRecommender` is where this phase's AI actually
lives**, and it's deliberately narrow: given a `Service`, it gathers active suppliers whose
`services_offered` includes that service's type, further narrows to ones whose recorded `airports()`
cover the service's leg (see below), computes each remaining candidate's `SupplierPerformance`, and asks
Claude to rank them — weighing the deterministic metrics against each supplier's freeform `notes`. The
notes are the genuinely unstructured part an LLM is suited for (a formula can't tell "closed for
renovation last month" from "great to work with" the way reading the sentence can); the metrics
computation itself doesn't touch the API. Same defensive pattern as `CreateFlightRequestFromExtraction`
in Phase 7: a recommended `supplier_id` is filtered against the actual candidate list before being
trusted, since Claude was only *given* real ids, not validated against them.

**Candidate suppliers are filtered by airport coverage, not just service type — a later addition once
`Supplier::airports()` turned out to exist but never actually be read by this class.** Recommending a
ground handler with zero presence at the flight's airport isn't a judgment call for an LLM to weigh, it's
operationally impossible, so `SupplierRecommender::filterByAirportCoverage()` excludes any candidate
whose recorded airports don't include the leg's origin or destination — *but only when that supplier has
airports recorded at all*. Coverage data isn't populated for every supplier yet, so a supplier with an
empty `airports()` relation is treated as "not yet recorded", not "confirmed absent", and stays a
candidate — same reasoning `ComputeSupplierPerformance`'s "no data" already applies to missing cost/
response-time history. The prompt tells Claude which airports each surviving candidate covers (or that
none are recorded) so a confirmed match can still be weighed as a stronger signal than an unrecorded one.

**"Suggest supplier" is a real form now, not a read-only list — the AI pre-fills a searchable picker in
the same modal instead of applying its pick silently.** Built in two steps at the user's direction: first
"the AI should choose one for him, and the user can change it whenever he wants" (auto-apply the top pick,
change it via the separate Edit action afterward), then "give me the ability to search for the supplier
and choose one" — folding the picker into the same modal instead of requiring a second action. The modal's
`->form()` is a `Filament\Forms\Components\View` (the ranked list + rationale, reused from the first
version, purely informational) followed by an ordinary searchable `Select::make('supplier_id')`, defaulted
to the AI's #1 recommendation via `->default()`. Nothing is written until the operator submits — closing
without saving leaves the service untouched, searching and picking a different supplier before saving
overrides the AI's default entirely, and a failed AI call (see below) still leaves a working, empty picker
rather than blocking manual selection. `SupplierRecommender` only runs once per modal open despite being
needed by both the list and the Select's default — `supplierRecommendationsFor()` caches it per-service on
the `RelationManager` instance for the request, since it's a real API call, not a free lookup.

**A real bug found via a genuine single-candidate case, not something `Http::fake()` coverage could
have caught**: with exactly one candidate supplier, Claude (Haiku 4.5, not forced to call the tool — see
`ClaudeClient`'s docblock on why `tool_choice` is left on `auto`) sometimes responded with a plain-text
clarifying question ("You've provided one candidate supplier, but typically there would be multiple...")
instead of calling `recommend_suppliers` at all — which `SupplierRecommender` correctly treats as a
failure (`ClaudeApiException`), surfacing as "Could not get AI suggestions right now" in the UI. This
will be common in real data, not an edge case: plenty of airport/service-type combinations only ever
have one supplier on file. The system prompt now says explicitly that a single candidate is a complete
answer to rank, not missing information, and that this is a non-interactive call with no way to ask a
follow-up — confirmed fixed against the real API, not just by reasoning about the wording. Existing
`Http::fake()`-based tests can't regress-test prompt wording like this (they assert on how the app
parses a *given* Claude response, not on what a real model actually decides to do with the prompt) —
verifying prompt changes like this one needs a real API call, done manually, not a new Pest test.

**`App\Domain\FlightRequests\Actions\CheckOperationalRisks` is the spec's "AI Risk Detection", and
like `CheckMissingInformation` it isn't an `AI/*` class** — every check (a service flagged `AtRisk`, a
passed deadline, a quote request unanswered for a week, a service inside 3 days of its deadline and
still unconfirmed) is a direct comparison against data the quote workflow above already produces. The
one genuinely judgment-based risk question — "should we be worried about this supplier" — is handled
by `SupplierRecommender` instead, where it's actually useful: before a supplier is assigned, not after.
Because this and `CheckMissingInformation` are both deterministic "things you check while reviewing a
flight" actions that happen to share a header-action UI pattern with the one real AI-driven action
(`markReviewed`), the trait that holds all three was renamed from `HasAiReviewActions` to
`HasFlightRequestReviewActions` in this phase — the old name overstated what was actually AI.

**Cost data can leak through an AI rationale, not just a table column.** `SupplierRecommender`'s output
is free-form text, and the metrics it reasons over include `averageCost` — so a rationale can end up
stating a supplier's average price outright, something Phase 6 carefully hid from anyone without
`finance.view_costs` at the field level. The "Suggest supplier" action is gated on
`finance.view_costs` for exactly this reason, not just `services.manage`: hiding the `cost` *column*
means nothing if the same number reaches the same user through an AI-generated sentence instead.

**Procurement gained `services.manage`** (previously view-only) — the same class of spec-supported gap
as Sales/`flights.manage` (Phase 5) and Sales+Finance/`services.view` (Phase 6). Procurement is who
actually talks to suppliers per the spec, and already held `finance.view_costs`, but had no permission
to trigger a quote request or record one at all until this phase — a gap that only became visible once
those actions existed to check permissions against (`SendSupplierInquiry`/`RecordSupplierInquiryResponse`
as of Phase 15 — `SendSupplierRequest`/`RecordSupplierQuote` originally). Documented inline in
`RolesAndPermissionsSeeder`.

**Phase 15 reworked the single-supplier quote cycle above into a multi-supplier one — "select several
suppliers, send inquiries to each, compare replies before picking one" from the workflow spec.**
`App\Domain\Services\Models\SupplierInquiry` is the new layer this needed: one row per candidate
supplier asked about one `Service`, `belongsTo Service`/`Supplier`/`SupplierContact` plus `requestedBy`
(`User`), cast to a small `SupplierInquiryStatus` (`Sent` → `QuoteReceived` → `Chosen`) that's
deliberately narrower than `ServiceStatus` — it only tracks "did this candidate quote us", not the
service's whole lifecycle. `Service.supplier_id`/`cost` now mean **"the supplier we chose"**, not "the
supplier we're asking" — a service can have several `SupplierInquiry` rows in flight at once, something
the old single `supplier_id` column had no way to represent.

**Three actions replace the old two, one per inquiry-lifecycle step, same "no generic edit, every state
change is a named action" convention as Quotation/Invoice:**

- **`SendSupplierInquiry`** (`SendSupplierRequest` originally) creates the `SupplierInquiry`, emails the
  chosen contact via the same `SupplierQuoteRequestMail`, and logs the outbound email as a
  `Communication` **on the inquiry itself**, not the `Service` — a service with three inquiries out now
  keeps three separate conversations instead of one blurred timeline, the same "a flight's several
  quotations each keep their own correspondence" reasoning `Quotation` already established. Bumps
  `Service.status` to `SupplierRequestSent` only on the first inquiry (`NotStarted`/`InformationRequired`
  → `SupplierRequestSent`) — a second candidate for the same service doesn't re-trigger it, and it never
  regresses a service already further along.
- **`RecordSupplierInquiryResponse`** (`RecordSupplierQuote` originally) sets `cost`/`notes`/
  `responded_at` and `QuoteReceived` **on the inquiry**, not the `Service` — recording what one candidate
  quoted doesn't decide anything yet.
- **`ChooseSupplierInquiry`** is new: the actual decision. Copies the winning inquiry's `supplier_id`/
  `cost`/`requested_at`/`responded_at` onto the `Service`, demotes any other inquiry on the same service
  that was previously `Chosen` back to `QuoteReceived` (re-picking after changing your mind never leaves
  two inquiries both marked `Chosen`), and only advances `Service.status` to `QuotationReceived` when it's
  still at `NotStarted`/`InformationRequired`/`SupplierRequestSent` — choosing a different supplier for a
  service that's already `Confirmed` (or later) updates the price without silently rewinding a status that
  reflects real progress.

**Two access surfaces, split by where a single `Service` is naturally in scope, not by "browse vs.
do" the way Quotation/Invoice split.** `ServicesRelationManager` keeps a per-service "Send RFQ" row
action — the AI-ranked suggestion list (`supplierRecommendationsFor()`, moved here from the old
"Suggest supplier") needs one specific service's type/leg to filter and rank against, which only a row
action naturally has; a flight-wide header action would need the service picked reactively first, with
no service in scope until then. The new `SupplierInquiriesRelationManager` tab is everything *after*
that — every inquiry across every service, grouped by service by default (same `Group::make()` pattern
Phase 14 established), with `recordResponse`/`chooseSupplier` row actions. It has no `CreateAction`: there's
deliberately one entry point for starting an inquiry (the Services tab), not two competing ones.

**The AI suggestions block is conditionally built, not just conditionally hidden.** "Send RFQ" is visible
to anyone with `services.manage` — Operations included, same as `SendSupplierRequest`'s old gate — but the
`supplierRecommendationsFor()` call (a real Claude API request) only happens when the caller also has
`finance.view_costs`, for the same cost-leaks-through-a-rationale reason Phase 8 gated "Suggest supplier"
on it. Operations gets a plain searchable supplier picker with no wasted API call, not just the AI panel
hidden after the fact.

**Phase 16 closes the loop the other direction: a supplier's reply email gets read for a price
automatically, instead of always needing a manual "Record response".** This is the same
`app/AI/RequestExtraction`-style split as Phase 7 — a deterministic matching step outside `app/AI`, then
an actual Claude call inside it — applied to inbound supplier mail instead of inbound customer mail.

**`App\Domain\Services\Actions\MatchSupplierReplyToInquiry` is plain domain code, not `AI/*`** — same
reasoning as `CheckMissingInformation`: matching an inbound email to the `SupplierInquiry` it's replying
about is a lookup, not something ambiguous for a model to resolve. It matches the email's `from_address`
against `SupplierContact.email`, case-insensitively (Postmark's `From` is already a plain address, no
`"Name <email>"` parsing needed), scoped to the tenant like every other query. **Only returns a match when
there's exactly one open (`Sent`, not yet responded to) inquiry for that contact** — a contact juggling two
simultaneous RFQs makes "which one is this reply about" a real ambiguity, and guessing wrong would be
worse than leaving it for the operator's ordinary "Record response" action, same "leaving it unmatched is
always safer than guessing" principle `CreateFlightRequestFromExtraction` already applies to dates. This
match is a free query, so it runs for *every* inbound email before any Claude call — a customer email
(the overwhelmingly common case) costs nothing beyond that one lookup finding no match.

**`App\AI\SupplierReplyExtraction`** is the AI half, structured exactly like `RequestExtraction`
(`Prompts`/`Extractors`/`DataTransferObjects`/`Jobs`, no `Actions` subfolder here — there's no
confidence-gated "create a record" step the way `CreateFlightRequestFromExtraction` has, just a value to
apply or not):

- **`SupplierReplyExtractionPrompt`** builds a tool schema with a single field, `cost` (number or null) —
  deliberately the *only* structured field. The system prompt is explicit that `null` means "no clear
  price stated" and covers every case that should produce it: a clarifying question, a decline, an
  out-of-office reply, a vague range, a different currency — "an operator would rather see nothing
  extracted than a wrong price recorded automatically," same reasoning as the leg-date gate in Phase 7.
- **`SupplierReplyExtractor`** calls `ClaudeClient` with the matched inquiry's service type as context (so
  the model knows what was actually asked about) and returns an `ExtractedSupplierReply` DTO.
- **`ExtractSupplierReplyFromEmail`** (a queued job, dispatched by `ReceiveInboundEmail` alongside
  `ExtractFlightRequestFromEmail` — every inbound email is now tried against both "is this a new request"
  and "is this a supplier reply") sets `CurrentCompany`, runs the match, and — only once a single open
  inquiry is found — calls the extractor. A `null` cost or a `ClaudeApiException` both just return early,
  leaving the inquiry and the Communication exactly where they were (on the `Company`, same as any other
  unmatched inbound email); nothing is lost, it just doesn't get auto-recorded.

**`RecordSupplierInquiryResponse` gained an optional `$sourceEmail` parameter for this** rather than the
AI path getting its own duplicate "set these fields" action. When given a `Communication`, it's moved onto
the inquiry directly (`communicable_type`/`communicable_id` assignment — not in `#[Fillable]`, same
"a form should never be able to move a Communication to a different subject" reasoning
`CreateFlightRequestFromExtraction` already established) instead of synthesizing a second, redundant
"quote received" entry via `LogCommunication` — the real email already says what the supplier quoted, so
recording it again as a separate paraphrased Communication would just be a second copy of the same
information. Manual entry (an operator typing in what came back by phone) has no email to move, so it
keeps synthesizing one, unchanged from Phase 15 — "whether the quote actually arrived by email or was
recorded from a phone call, the timeline records it as received either way."

**Not modeled:** confirmation detection — a supplier's reply saying "confirmed, we'll be there" doesn't
set anything yet. `SupplierInquiryStatus` has no `Declined` case either. Both are Phase 17's territory
(the supplier-order-confirmation step), not a gap in this phase — Phase 16 is deliberately scoped to the
one structured fact (a price) that's unambiguous to extract and safe to auto-apply.

**Phase 17 closes the workflow's last gap: booking the chosen supplier and confirming they'll actually
show up, with the same manual-or-AI-detected shape as Phases 15/16.** `SupplierInquiryStatus` gains a
fourth case, `Confirmed`, after `Chosen` — "did *this* supplier confirm the booking" is squarely about
one candidate inquiry, not the whole service, so it belongs on the inquiry (unlike `Completed`/
`Cancelled`, which stay on `Service` since they're about the whole service, not one supplier
relationship). `supplier_inquiries` gained its own `confirmation_requested_at`/`confirmed_at` pair,
deliberately separate from `requested_at`/`responded_at` (the quote cycle) — reusing those would erase
the response-time history `ComputeSupplierPerformance` reads from the RFQ round when a service goes
through a second round-trip to get booked.

**`SendSupplierConfirmation`** emails the chosen inquiry's contact via a new `SupplierBookingConfirmationMail`
— referencing the price *they* quoted (`inquiry.cost`, not `Service.selling_price`), same customer/supplier
financial boundary `SupplierQuoteRequestMail` already draws — and sets `confirmation_requested_at`. Only
reachable once an inquiry is `Chosen`, same one-entry-point-per-step shape as the rest of this workflow.

**`ApplySupplierConfirmation`** is the actual confirmation — reached manually ("Mark confirmed" on
`SupplierInquiriesRelationManager`) or automatically (`ExtractSupplierConfirmationFromEmail`). Same
`$sourceEmail`-or-synthesized-`Communication` dual shape as `RecordSupplierInquiryResponse`. Sets the
inquiry to `Confirmed` and mirrors it onto `Service`: `supplier_confirmed_at` always gets the timestamp (a
plain fact, safe to record regardless), but `Service.status` only advances to `Confirmed` when it isn't
already there or further along (`Completed`/`Cancelled`) — same "only ever move forward, never silently
rewind real progress" guard `ChooseSupplierInquiry` already uses for the price.

**`MatchSupplierConfirmationReplyToInquiry` and `App\AI\SupplierConfirmationExtraction`** mirror Phase 16's
matcher/extractor pair exactly, one stage later: the matcher looks for exactly one inquiry that's `Chosen`
with a confirmation sent but not yet confirmed (rather than `Sent` awaiting a first price), and the tool
schema is a single `confirmed: boolean` field — true only for an unambiguous "yes, we'll be there", false
for everything else (a decline, a question, an out-of-office reply). Kept as a genuinely separate matcher
class from `MatchSupplierReplyToInquiry` rather than a parameter on it: "which open thing is this reply
about" is a different question at each stage of an inquiry's life, and conflating the two risks matching a
price reply against a confirmation-stage inquiry or vice versa. `ExtractSupplierConfirmationFromEmail` is
the third job `ReceiveInboundEmail` now dispatches on every inbound email, alongside the customer-request
and supplier-price ones — matching first (free), only calling Claude once a genuine single candidate is
found.

**`CheckOperationalRisks` gained one more finding**, mirroring the existing stale-quote-request check
exactly but for the confirmation stage: a `Chosen` inquiry whose confirmation was requested more than a
week ago (`STALE_SUPPLIER_REQUEST_DAYS` — renamed from `STALE_QUOTE_REQUEST_DAYS` now that it covers both
request types) with no reply yet. Iterates `FlightRequest::supplierInquiries()` (the `hasManyThrough`
Phase 15 built), same as the existing checks iterate `services()`/`quotations()`/`invoices()`.

**Not modeled:** a `Declined` inquiry status — a supplier's reply that clearly declines rather than
confirms still just leaves the inquiry `Chosen` with nothing recorded, same "extract only the one safe
fact, leave everything else for a human" scope discipline Phase 16 established for prices. Worth adding
once real usage shows operators actually want to distinguish "no reply yet" from "they said no" instead of
just handling both by picking a different supplier manually.

## Notifications & reminders

Phases 7 and 8 built three "check something and show it in a modal" actions —
`needsReview()`/`CheckMissingInformation`/`CheckOperationalRisks` — that an operator only ever sees if
they happen to open the right flight and click the right button. Phase 9 doesn't add a new check; it
makes the existing ones proactive.

**`App\Domain\FlightRequests\Actions\BuildFlightRequestDigest`** takes one `Company` and returns every
active flight's outstanding messages (an unreviewed AI draft, plus whatever `CheckMissingInformation`
and `CheckOperationalRisks` already report), grouped by recipient: the flight's `assignedUsers` when
there are any, or every `flights.manage` holder in the company when there aren't — which is the normal
case for a fresh AI draft, since nobody's been assigned to it yet. "Active" excludes `Completed`,
`Invoiced`, `Closed`, and `Cancelled` — nothing left to act on there. Kept as a pure function of one
company's data (no sending, no side effects) so "what's currently outstanding" is testable on its own.

**`SendFlightRequestDigests`** is the actual scheduled job: it loops every `Company` — a genuine
cross-tenant loop, the kind Multi-tenancy above calls out as the one legitimate case, with
`CurrentCompany` set explicitly before each company's digest is built, same convention as every other
job — and sends one `Filament\Notifications\Notification::make()->sendToDatabase($user)` per user who
has anything outstanding. Fired daily at 07:00 by `app:send-flight-request-digests`
(`routes/console.php`'s `Schedule::command(...)`), which just calls the Action — the command itself has
no logic of its own, same "one Action, one job" convention used everywhere else.

**Two deliberate scope decisions, not oversights:**

- **No de-duplication against what was already sent.** This is a daily snapshot of what's currently
  outstanding, not an event log — an issue still open after yesterday's digest is still worth surfacing
  today. Tracking "have I already told this user about this specific finding" would need persistent
  per-finding state for a marginal reduction in repeat notifications; not worth it until real usage
  shows the digest is too noisy.
- **In-app only (Filament's database-notifications bell — `->databaseNotifications()` +
  `->databaseNotificationsPolling('30s')` on the panel), no email.** Postmark/Mailable delivery already
  exists (Phase 8's `SupplierQuoteRequestMail`) and would be a small addition, but a daily email on top
  of a daily in-app digest is a product decision about how noisy this should be, not a technical one —
  better made once someone is actually using the in-app version and says they want email too.

`notifications` — Laravel's standard table — didn't exist before this phase; `User` has carried
`Notifiable` unused since the Phase 0/1 scaffold.

## Quotation

`App\Domain\Quotations\Models\Quotation` is the spec's "Quotation Sent to Customer" step — the formal
offer built from a flight's priced services and sent to the customer for approval.
`quotations.view`/`quotations.manage` existed unused in `RolesAndPermissionsSeeder` since Phase 1,
waiting for this module.

**A Quotation is a snapshot, not a live view.** `CreateQuotationFromServices` copies each priced,
non-cancelled `Service` (type label, cost, selling price) into a `QuotationLineItem` at generation time.
Once created, a quotation's totals never drift even if the underlying `Service.selling_price` changes
afterward — a customer who accepted a quote is agreeing to a fixed number, not whatever the live data
happens to say later. This is also why `totalCost()`/`totalSellingPrice()`/`profitMargin()` are computed
from `lineItems` on read rather than stored columns on `Quotation` — same "a stored copy is just
something that can drift" reasoning as `Service::profitMargin()`, applied one level up. A `Service` with
no `selling_price` yet is simply left out of the snapshot; there's no partial-price line to show.

**Multiple quotations per flight are the normal case, not an edge case.** `CreateQuotationFromServices`
always creates a new `Quotation` rather than editing an existing one — a rejected quote gets superseded
by a fresh one after re-pricing, and both stay visible in `QuotationsRelationManager`'s history. This is
also why `QuotationPolicy::delete()` returns `false`: a superseded quotation is history, not a mistake
to clean up, same "no hard delete" convention as every other core record.

**No generic edit — every state change is a named action**, mirroring Phase 8's supplier-quote actions:

- **`SendQuotation`** emails `App\Mail\QuotationMail` to the customer's `billing_email` (throwing if
  there isn't one — the panel action catches this and shows a friendly notification instead of a 500),
  logs the send as a `Communication` **on the `Quotation` itself** (not the flight — a flight's several
  quotations each keep their own correspondence), and moves `Quotation` → `Sent` /
  `FlightRequest.status` → `QuotationSent`. `QuotationMail` shows only `selling_price`, never `cost` —
  same customer/tenant financial boundary `SupplierQuoteRequestMail` draws for suppliers, just facing
  the other direction.
- **`RecordQuotationResponse`** is how "the customer accepted" enters the system — there's no customer
  portal, so an operator always records what came back by phone or email reply, same pattern as
  `RecordSupplierQuote`. Accepting is the one place in the app that sets `FlightStatus::Confirmed`;
  rejecting leaves the flight's status alone (still `QuotationSent`) so the operator can generate and
  send a revised quotation without the flight appearing to regress.

**Two access surfaces, same "standalone for browsing, RelationManager for doing" split as
Documents/Communications** — but the split here is by *purpose*, not just scope. The standalone
`QuotationResource` (List + View only, no create/edit routes at all) is the company-wide pipeline view
Sales/Finance/Management actually want ("show me everything sent, everything overdue"). The actions
that change state — Generate/Send/Mark accepted/Mark rejected — live only on `QuotationsRelationManager`
under `FlightRequestResource`, since every one of them needs the owning flight in scope anyway.

**Deliberately not built: PDF generation.** The spec's "send a quotation" is satisfied here by a
formatted HTML email (`resources/views/mail/quotation.blade.php`) with a line-item table, not a PDF
attachment. Building a generic document-generation capability speculatively — before Quotation's actual
field requirements were known — was explicitly deferred when Phase 9 was scoped (see that phase's
"document generation" alternative that was passed over); now that a real, first customer-facing
document exists, a PDF version is a well-scoped future addition to build *from* this Mailable, not
infrastructure to guess at ahead of it.

**Management gained `quotations.view`** (spec-supported gap fix, same class as Sales/Finance/Procurement
in earlier phases) — Management is view-only across every other financial/operational module, and
quotations was simply the one left out because there was nothing to view until this phase.

**`CheckOperationalRisks` gained one more finding**: a `Sent` quotation past its `valid_until` with no
response (`Quotation::isExpired()`). `QuotationStatus::Expired` exists as an enum case for display but
is never set automatically by a scheduled job — same "compute on read, don't maintain a stored status
that can go stale" principle as everywhere else; `isExpired()` and this risk finding are what actually
surface it, and Phase 9's daily digest picks it up for free through the same `CheckOperationalRisks`
call `BuildFlightRequestDigest` already makes.

**Phase 18 lets `CreateQuotationFromServices` scope to one leg instead of always the whole flight** —
closing the "generate a quotation for the full leg or the full request" gap in the original workflow ask.
`Quotation` gained a nullable `flight_leg_id` (null means the original whole-flight behavior); when set,
the services snapshot is additionally filtered to that leg (`->where('flight_leg_id', $leg->id)`) before
becoming line items. **Stored on the `Quotation`, not inferred from its `lineItems`** — a whole-flight
quotation whose services all happen to sit on one leg would otherwise be indistinguishable from a
deliberately leg-scoped one by inspecting line items alone, and that ambiguity is exactly the kind of
"looks derivable but actually isn't" trap worth a real column instead. `displayLabel()` and the customer
mail (`mail.quotation`) both reflect the scope — the email lists only the scoped leg's route/departure
instead of every leg on the flight when `flight_leg_id` is set, so a leg-scoped quotation doesn't
reference stops the customer isn't being quoted for. (Fixed a latent null-safety gap in the same template
while touching it: a leg's `departure_at` can be null — see "Flight legs" above — and the old
`->toDayDateTimeString()` call had no guard for that case.)

**`QuotationsRelationManager`'s "Generate" form gained a `flight_leg_id` Select**, options narrowed to legs
that actually have at least one priceable service — no point offering a scope that would generate an empty
quotation. Same "the options list is a UI convenience, not the actual boundary" pattern as
`ServicesRelationManager`'s own `flight_leg_id` field: a leg id submitted directly that doesn't belong to
this flight is still rejected server-side via a form `rule()`, regardless of what the picker offered.

## Flight execution

`FlightStatus`'s `Confirmed → InOperation → Completed → Invoiced → Closed` tail has existed since Phase
5 with nothing behind it beyond a raw `Select` field on the edit form — the same shape of gap Phase 8
closed for `ServiceStatus` (`SupplierRequestSent`/`QuotationReceived` with no action) and Phase 10
closed for `QuotationStatus`. Phase 11 operationalizes the `Confirmed → InOperation → Completed` leg;
`Invoiced`/`Closed` are Finance's territory (Phase 12, below) — `CompleteFlight` deliberately stops at
`Completed` and goes no further, handing off to `SendInvoice`/`RecordInvoicePayment` from there.

**`CheckFlightReadiness` is advisory, not a gate — the one deliberate departure from how this might
read at first.** It checks whether every non-cancelled service is `Confirmed`/`Completed`, whether the
flight has any services at all, and whether a quotation was actually `Accepted` (a defensive check,
since the raw status `Select` still allows a manual override that skips `RecordQuotationResponse`
entirely). `MarkFlightInOperation`/`CompleteFlight` show these findings in the confirmation modal before
the operator proceeds, but never block the transition on them — same "the system informs, the human
decides" principle `CheckMissingInformation`/`CheckOperationalRisks` already established. A plane can
still fly with an imperfect paper trail; the point of this check is making sure nobody does that by
accident, not preventing it outright.

**The raw `status` `Select` field on the edit form is untouched, deliberately** — `MarkFlightInOperation`
and `CompleteFlight` are a *guided* path for the common forward case, not a replacement for the existing
one. An operator who needs to correct a status (a misclick, an unusual workflow) still has the plain
field as an escape hatch; Phase 11 adds structure without removing flexibility that already existed.

**`operation_started_at`/`completed_at`** are set by their respective actions — same "track the
checkpoint, don't just flip a status" convention as `Service.quote_requested_at`/`quote_received_at` and
`Quotation.sent_at`/`responded_at`. Both actions log a `SystemEvent` `Communication` on the flight, same
as everywhere else a state transition happens.

**A latent permission gap found and fixed while wiring this**: `markReviewed` (Phase 7's "mark AI draft
reviewed" action) had no permission check of its own — it relied on reaching `ViewFlightRequest` at all,
but that page is reachable on `flights.view` alone (Procurement, Finance, Management all get there
without `flights.manage` — see Service Management's `ViewRecord` note). A view-only role could click it.
Fixed by gating it on `flights.manage`, same as the two new execution actions it now sits beside in
`HasFlightRequestReviewActions`/`HasFlightExecutionActions`. Worth a broader note: any header action
gated only by a data condition (`->visible(fn () => $record->someState)`) and not also by a permission
check inherits whatever the *page's* minimum access level is, not the action's actual sensitivity — that
gap is easy to introduce again on a future action if this isn't kept in mind.

**Phase 19 makes `CheckFlightReadiness` passive.** Before this, its findings only ever surfaced when an
operator explicitly clicked "Mark in operation"/"Mark completed" — a flight sitting unconfirmed a day
before departure gave no signal unless someone happened to open it. `App\Domain\FlightRequests\Actions\CheckFlightReadinessWarning`
is the new passive layer: it calls `CheckFlightReadiness` (unchanged, same advisory-only behavior for the
explicit transition modals) but only bothers when departure is close (`WARNING_WINDOW_DAYS`, a plain class
constant like `CheckOperationalRisks`' own thresholds — not a per-tenant setting, revisit if real usage
asks for one) and the flight's status hasn't already moved past the point where "not fully confirmed"
stops being useful (`InOperation`/`Completed`/`Invoiced`/`Closed`/`Cancelled`).

**Kept as its own class rather than a second method on `CheckFlightReadiness`** — every other `Check*`
action in this app does exactly one job, and layering a date/status gate on top is a genuinely different
concern from "what's wrong right now", not just a different presentation of the same answer.

**Three passive surfaces, one shared check:**

- **The flight list** (`FlightRequestResource::table()`) gets a leading icon column — `->state()` computes
  `CheckFlightReadinessWarning` once per row, and `icon()`/`tooltip()` both just read that resolved state
  rather than each re-running the check. Deliberately not optimized further than eager-loading `services`
  in `modifyQueryUsing` — `CheckFlightReadiness`'s accepted-quotation check still issues one query per row
  regardless, the same "not worth it until real usage shows a problem" call Phase 12 already made about
  date-range filtering on the financial summary. A single tenant's active-flights list isn't expected to
  be large enough for that to matter.
- **The kanban board** (`kanban-board.blade.php`) shows the same warning as a small icon next to the
  card's title, via the already-loaded Heroicons blade components (`<x-heroicon-o-exclamation-triangle>`)
  the Mailpit panel already uses elsewhere in this app.
- **The view page** (`FlightItineraryOverview` widget) shows a banner above the leg-by-leg breakdown,
  spelling out the actual `CheckFlightReadiness` findings — this page has the room the other two don't.

**Not modeled:** the daily digest (`BuildFlightRequestDigest`) doesn't pick this up — deliberately scoped
out of this phase, which is about passive *in-app* surfaces, not another notification channel. Worth
revisiting once these three surfaces are actually in use.

## Customer status updates

**Phase 20** closes the spec's "throughout the process, the user can send the client a flight status" —
`App\Domain\FlightRequests\Actions\SendFlightStatusUpdate` emails the customer a per-leg, per-service
status snapshot (`FlightStatusUpdateMail`) and logs it as a `Communication` on the `FlightRequest` itself,
same `throw a RuntimeException when there's no billing_email` shape `SendQuotation`/`SendInvoice` already
use.

**Deliberately not a `FlightStatus` transition, unlike every other "Send*" action in the app.**
`SendQuotation` moves the flight to `QuotationSent`, `SendInvoice` to `Invoiced` — sending a status update
changes nothing, since "throughout the process, any process" means it has to be callable at any point in
the lifecycle without implying a step was just completed. This is also why it's wired into
`HasFlightStatusUpdateAction`, a new trait, rather than folded into `HasFlightRequestReviewActions` or
`HasFlightExecutionActions` — both of those exist specifically to check something or move `FlightStatus`
forward, and this does neither.

**Deliberately price-free.** `QuotationMail`/`InvoiceMail` are financial documents and show
`selling_price`; this one shows only each service's type and status — "is everything on track", not a
pricing breakdown the customer already has from their quotation. There's no field-level gate to bypass
here the way `Service`'s own columns need one: `cost`/`selling_price` simply never enter the template at
all.

**Gated on `flights.manage` only, same as every other flight-lifecycle action** — no extra "must be the
assigned user" restriction, since no other action in this resource has one either and adding it here alone
would be an inconsistent, unrequested restriction.

## Finance

The final phase per the roadmap — invoicing and cross-flight financial reporting, closing out
`FlightStatus` all the way to `Closed` and finally giving Management's `reports.view` permission
(unused since the Phase 1 seeder) something to display.

**`App\Domain\Finance\Models\Invoice` has no line items or stored total of its own — it delegates to
the `Quotation` it was generated from.** `CreateInvoiceFromQuotation` sources the flight's `Accepted`
quotation (throwing if there isn't one, `->latest()` guarding the unusual case of more than one) and
just references it; `Invoice::totalAmount()`/`profitMargin()` call straight through to
`$this->quotation->totalSellingPrice()`/`profitMargin()`. This is one step further than Quotation's own
"compute from lineItems, don't store a copy" reasoning: since the accepted Quotation's `lineItems` are
already an immutable snapshot, re-snapshotting the same numbers into a second frozen table would just be
a second copy of data that already can't drift. Simpler to have nothing to keep in sync at all.

**Every state change is a named action, no generic edit — same shape as Quotation's `SendQuotation`/
`RecordQuotationResponse`:**

- **`SendInvoice`** emails `App\Mail\InvoiceMail` to the customer's `billing_email` (amount and due
  date only, never cost — same boundary `QuotationMail` already draws), logs it on the *Invoice's* own
  timeline, and moves `Invoice` → `Sent` / `FlightRequest.status` → `Invoiced`.
- **`RecordInvoicePayment`** is how "the customer paid" enters the system — no payment gateway, no
  partial payments, just an operator recording what came back, same pattern as
  `RecordSupplierQuote`/`RecordQuotationResponse`. This is the one place `FlightStatus::Closed` gets
  set — the final stop in the flight's entire lifecycle, four phases after `FlightStatus` was first
  defined with nothing but `Cancelled` actually reachable.

**No `invoices.*` permission — unlike Quotation, reuses `finance.manage`/`finance.view_prices`
instead of minting a new pair.** `quotations.*` sat unused in `RolesAndPermissionsSeeder` since Phase 1,
a clear signal it was pre-planned as its own permission. Nothing equivalent exists for invoices, and
`finance.manage`/`finance.view_prices` already say exactly what's needed — inventing a parallel
`invoices.*` pair when an existing permission fits would just be two ways to express the same grant.
`InvoicePolicy::viewAny`/`view` uses `finance.view_prices` (Sales already relies on the same permission
to view Quotations, and an invoice amount is exactly that — a selling price), while
`create`/`update` uses `finance.manage`, since invoicing is Finance's job per the spec, not Sales's.

**`invoice_number` is generated in the Action, not a model `creating` hook** — `CreateInvoiceFromQuotation`
counts existing invoices for the flight's company (`Invoice::withoutGlobalScopes()->where('company_id', ...)`,
deliberately not trusting `CompanyScope`/`CurrentCompany` alone for a number that must be exactly right
regardless of ambient tenant context) and formats `INV-000001` upward. Keeping this in the Action instead
of a `static::booted()` hook avoids relying on trait-boot ordering (`BelongsToCompany`'s own `creating`
hook needs to run first to populate `company_id`) — a plain, testable method beats a hook whose
correctness depends on *when* Eloquent decided to call it.

**`App\Domain\Finance\Actions\ComputeFinancialSummary` and `FinancialSummaryWidget`** are the spec's
"financial reports" — total invoiced, collected, outstanding, overdue (count and amount), and profit
margin realized on paid invoices. Deterministic arithmetic over real invoice data, not an `AI/*` class,
same reasoning as every other check/report in this app. The widget is gated on `reports.view` to appear
at all, then the revenue-shaped stats additionally need `finance.view_prices` and the margin stat
additionally needs `finance.view_costs` — field-level-on-top-of-screen-level gating, consistent with
every other financial figure in the app, even though every current `reports.view` holder happens to have
both today. **Finance gained `reports.view`** in this phase (spec-supported gap fix) — the exact
sentence already quoted in Service Management ("Finance's whole job per the spec is 'supplier costs,
profitability, financial reports'") names reports explicitly, yet only Management ever held the
permission since the Phase 1 seeder. **Not built:** date-range filtering on the summary — it's an
all-time total for now; a natural follow-up once real usage shows which window actually matters, not
guessed at here.

**`CheckOperationalRisks` gained a final finding**: a `Sent` invoice past its `due_date` with no payment
recorded — same `isOverdue()`-computed-on-read shape as `Quotation::isExpired()`, and picked up for free
by Phase 9's daily digest through the same call `BuildFlightRequestDigest` already makes. This closes
the loop the spec's original "AI Risk Detection" feature started back in Phase 8: every financial
document type this app has (`Service` quotes, `Quotation`, `Invoice`) now has its own staleness check
feeding the same list.

**Phase 21 regrouped `QuotationResource`/`InvoiceResource` out of `Operations` and into their own
`Accounting` sidebar section** — the same `getUrl`/`$navigationGroup` sidebar mechanism every other
resource already uses, not the custom multi-item `getNavigationItems()` override
`FlightRequestResource` needed in Phase 13. That override was only necessary there because one resource
had to contribute *two* sidebar links to the *same* group (My Assigned/All Requests); Quotations and
Invoices are still one resource, one link each, just sharing a group label the two of them happen to
both set — plain `$navigationGroup = 'Accounting'` on each is the whole change.
`$navigationSort` (`1`/`2`) keeps Quotations listed first, matching the order a flight actually produces
them in — a quotation exists before the invoice generated from it ever can. `Operations` had no other
resource contributing to it, so the group simply stops appearing in the sidebar rather than being left
behind half-empty.

## What NOT to do

- Don't add a model without `BelongsToCompany` unless it's genuinely global (e.g. `Airport`,
  `Country` — shared reference data, not owned by a tenant).
- Don't forget a field in `#[Fillable]` and assume mass-assignment will just work — Eloquent silently
  drops non-fillable attributes from `create()`/`fill()` rather than throwing, so the failure mode is a
  `NOT NULL constraint` error (or worse, a silently-null field) far from the line that's actually
  wrong. Hit three times so far (`Document`'s storage metadata, `Communication`'s `author_id`,
  `Aircraft`'s `customer_id`) — check this first when a create fails with a NOT NULL error on a field
  the form clearly submitted. If a field is only ever set by trusted application code (never a form),
  it can still go in `#[Fillable]` — mass-assignment protection is about what a *form* can set, not
  about hiding a field from your own Actions.
- Don't call the Claude API directly from a Filament resource or an Action outside `app/AI` — route
  it through an `AI/*` class so retries, logging, and failure handling are consistent.
- Don't put validation in the model. Use Form Requests (Filament form validation for panel forms,
  `FormRequest` classes for any plain HTTP endpoints).
