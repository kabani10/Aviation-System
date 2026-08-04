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
external network call). Enforced for the **Admin** role specifically, not optional-everywhere: Admin
bypasses every permission check (`Gate::before` in `AppServiceProvider`), so it's the one role with no
lesser-privileged fallback if the account is compromised.

Two middleware, both in `AdminPanelProvider`'s `authMiddleware`, in this order:

1. **`RequireTwoFactorForAdmins`** — an Admin with no confirmed 2FA is redirected to the setup page
   (`App\Filament\Pages\TwoFactorAuthentication`) before reaching anything else in the panel.
2. **`EnsureTwoFactorChallengeCompleted`** — anyone with 2FA enabled must pass a post-login code
   challenge (`/two-factor-challenge`, outside the panel) once per session before proceeding.
   Filament's `Login` page calls `Auth::attempt()` directly (full session established immediately) —
   there's no "authenticated but not yet 2FA-cleared" state to hook into upstream, so this is
   enforced downstream instead, gated on `session('2fa_passed')`.

That session key is cleared on every `Illuminate\Auth\Events\Login` (see `AppServiceProvider`) —
without that, a session cookie planted before login (session fixation) would carry a stale
`2fa_passed = true` forward and let an attacker skip the challenge for whoever logs in on it.

Recovery codes are consumed on use (removed from the stored array), and 2FA state changes
(enabled/disabled/recovery code used) are written to the activity log explicitly — see below.

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
what every other tenant reads. They're seeder-managed (`ReferenceDataSeeder`, a starting set of major
business-aviation hubs, not an exhaustive import — extend the list as real usage needs airports it's
missing) and exposed read-only wherever a picker is needed (`Supplier`'s "airports covered"). If a
platform-operator admin panel is ever built, that's where Country/Airport CRUD belongs — not here.

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

**Not modeled, deliberately:** "average response time", "previous prices", and "service quality" from
the original spec. These are computed from real supplier interactions (quotes sent, confirmations
received) that don't exist until Service Management (Phase 6) — a static rating field today would be a
number nobody updates. `notes` (freeform) covers "previous operational problems" in the meantime, since
that's genuinely just something procurement writes down, not something derived from data.

`SupplierPolicy` / `SupplierContactPolicy` consume `suppliers.view` / `suppliers.manage` — permissions
that already existed in `RolesAndPermissionsSeeder` since Phase 1 (Operations and Management view-only,
Procurement both); this is the first module to actually use them, same permission-over-role pattern as
`CustomerPolicy`.

## Flight Requests

`App\Domain\FlightRequests\Models\FlightRequest` is the central record from the original spec —
customer, aircraft, route (`originAirport`/`destinationAirport`, both real `Airport` references),
times, passenger/crew counts, `FlightStatus`, assigned employees (`assignedUsers`, a plain
`BelongsToMany` to `User`), plus `HasDocuments`/`HasCommunications` — this is what those two modules
were built for. `FlightStatus` mirrors the spec's Step 11 lifecycle with one addition: `Cancelled`,
which the spec covers for individual services but never states for the flight itself — an omission,
not a deliberate scope line, so it's included.

**Not modeled yet:** flight-level costs/selling prices (needs Quotation, Phase 10, and Finance, Phase
12, to aggregate profitability across services). Per-service cost/selling price exist as of Phase 6 —
see Service Management below. `requested_services_summary` stays even after a flight has real `Service`
records: it's the customer's original ask in their own words, not something structured data replaces.

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

## Service Management

`App\Domain\Services\Models\Service` — one line item on a flight (ground handling, fuel, a landing
permit), `belongsTo FlightRequest`, `BelongsToCompany` directly (same "a join isn't what CompanyScope
filters on" reasoning as everywhere else). No standalone `ServiceResource`: unlike Documents/
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

**Not modeled:** "operational risks" from the spec. That's AI Risk Detection reading deadlines,
confirmations, and supplier responses across services — not a field an operator fills in by hand.
`HasDocuments` is on the model (a service's own permit/certificate belongs here, not on the flight),
but there's no dedicated document UI for it yet: Filament doesn't nest a `RelationManager` inside
another `RelationManager`, and `Service` has no top-level resource of its own to hang one off.

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
