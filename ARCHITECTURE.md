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
email lands on the `Company` itself for now (`ReceiveInboundEmail`) — matching it to a specific flight
needs the Flight Request module, which doesn't exist yet. Attachments become `Document`s on the
`Communication`, not on the company directly, since they belong to the email.

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

**Deliberately not modeled yet:** the original spec's "preferred suppliers" per customer. It needs the
Supplier module (Phase 4) to be a real relationship; a placeholder field now would just get thrown away
and rebuilt, which is the "half-finished implementation" this project's conventions explicitly avoid.

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
