# FORCE-RLS Route-Binding Guardrail

**Status:** Rule documented and enforced by an automated regression test 2026-08-20. Applies to every plain route in `routes/web.php` and `routes/webhooks.php`.

---

## The rule

A plain route must never declare an implicitly-bound Eloquent model parameter (`Document $document`-style, resolved by Laravel's `SubstituteBindings` middleware) for a table that carries FORCE ROW LEVEL SECURITY. The route parameter must instead be a plain `string`/`int` identifier, resolved manually **after** tenant context has been established.

This is not a style preference — an implicit binding on a FORCE-RLS table in this app is silently and unconditionally broken. It would always resolve to "no rows found" (a 404, indistinguishable from the record genuinely not existing), never to the intended record.

## Why: middleware order + FORCE RLS

Two facts combine to make implicit binding unsafe here:

1. **PostgreSQL FORCE ROW LEVEL SECURITY.** Every tenant-owned table this rollout has activated carries `ENABLE ROW LEVEL SECURITY` + `FORCE ROW LEVEL SECURITY`, with a policy predicate that checks the row's `firm_id` (or equivalent) against `current_setting('app.current_firm_id', true)`. With no `app.current_firm_id` set for the current database session/transaction, the policy predicate never matches — the table looks completely empty, even to the application's own database user, even though rows exist.

2. **This app's global middleware-priority order runs `SubstituteBindings` ahead of any custom tenant-context middleware.** That ordering is fixed in `bootstrap/app.php` (`prependToPriorityList()`) and is out of scope for this guardrail to change. Laravel resolves implicit route-model bindings during `SubstituteBindings`, which means the query the framework runs to resolve `Document $document` always executes **before** any middleware has had a chance to `SET LOCAL app.current_firm_id`.

Put together: an implicit binding on a FORCE-RLS table always queries with no tenant context active, always sees zero rows under FORCE RLS, and therefore always 404s — not a security hole exactly (it fails closed), but a route that can never work, and a trap for the next person who "fixes" it by loosening the RLS policy or bypassing tenant context instead of just not using implicit binding.

## The preferred pattern

1. Route parameter is a plain `string`/`int` (the public identifier — typically a `uuid` column), never a type-hinted `App\Models\*`/`App\Marketplace\Models\*` parameter.
2. Controller authenticates/resolves the acting user first (`Auth::user()`, a guard-specific lookup, etc.) and aborts (401/403) if there is none.
3. Controller establishes tenant context from the **actor**, not from the route parameter — typically `TenantContextService::runWithFirmContext()` (or a narrow self-lookup context helper for a one-hop bootstrap query) — never by trusting a firm/tenant id embedded in the URL itself.
4. The target model is queried by its public identifier **inside** that same tenant-context callback, alongside any eager-loaded relations the authorization check needs — keeping lookup and authorization inside one context window avoids the same class of bug (a relation touched after the context closes sees nothing).
5. Authorization runs against the resolved record inside that same callback, using the domain's real authorization boundary (a dedicated `*SecurityService`/`*AccessPolicyService`), not the route's middleware alone.
6. A record belonging to a different tenant is therefore genuinely invisible under RLS (404) rather than bound-then-rejected; a same-tenant but unauthorized request still reaches the authorization check and gets a real 403.

## Reference implementations

Two controllers already implement this pattern for the `documents` table (FORCE-RLS, no self-lookup carve-out) and are the concrete examples to copy:

- `app/Http/Controllers/Firm/DocumentDownloadController.php` — resolves the firm side via `User::activeFirmUser()`.
- `app/Http/Controllers/ClientPortal/DocumentDownloadController.php` — resolves the client side via `ClientPortalUser::client_id` -> `Client::firm_id` (a narrow self-lookup context, since `client_portal_users` itself carries no RLS but `clients` does).

Both controllers' own docblocks restate this same reasoning inline, and both take `string $document`, never `Document $document`.

`app/Http/Controllers/Integrations/OAuthConnectionController.php` established the same shape first, for `firm_integrations`.

## Enforcement

`tests/Feature/Governance/ForceRlsRouteBindingGuardrailTest.php` reflects every route registered in `routes/web.php` and `routes/webhooks.php` (via the booted `Route` facade, filtered to application-owned, non-Filament actions), inspects each controller/action's parameters for an implicit `UrlRoutable`-typed binding against an `App\Models\*`/`App\Marketplace\Models\*` class, and fails if that model's table is in `RowLevelSecurityCoverageMappingService::forcedTables()` — the same canonical FORCE-RLS registry the rest of the RLS rollout already trusts, not a second hardcoded table list. A second, narrower test in the same file pins the two document-download routes above to the safe pattern directly, so the guardrail does not depend solely on the general reflection scan continuing to reach them correctly.

Filament panel routes are out of scope for this guardrail: every Filament resource page's `mount()` signature is `int|string $record` (confirmed by direct source read of `vendor/filament/filament/src/Resources/Pages/*.php`), never a typed Eloquent parameter — Filament resolves records through its own mechanism, not `SubstituteBindings`, so this class of bug cannot occur there.

## Non-goals

This guardrail does not change `bootstrap/app.php`'s global middleware-priority order — reordering `SubstituteBindings` relative to tenant-context middleware is a materially larger change (it would affect every route in the app, not just FORCE-RLS ones) and is explicitly out of scope here. It also does not audit or change any existing route or controller; at the time this was written, every route in `routes/web.php` and `routes/webhooks.php` already independently followed the pattern above.
