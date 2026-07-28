<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * client_portal_users — Checkpoint 4 ("Plaid financial evidence
 * add-on"), Client Portal authentication foundation
 * (checkpoint4-combined-design.md §5/§10;
 * checkpoint4-design-matter-and-client-portal.md §2.6.1). A thin, 1:1
 * credential/session shell around `Client` — NOT `Client` itself made
 * Authenticatable (see ClientPortalUser's own class docblock for the
 * full reasoning). `Client` is already BelongsToTenant + FORCE-RLS
 * protected, and authentication is precisely the bootstrap moment
 * where no firm tenant context exists yet — overloading `Client` as
 * the login identity would require either a permanent RLS carve-out on
 * `clients` for every future caller, or an out-of-band firm resolution
 * before the password check. This table exists specifically so that
 * concern narrows to a dedicated, purpose-built table instead.
 *
 * No `firm_id` column of its own — deliberately, matching
 * `matter_assignments`' own established "isolation is transitive
 * through the parent" pattern
 * (2026_07_05_600017_create_matter_assignments_table.php's own
 * docblock): isolation here is transitive through
 * `client_id -> clients.firm_id`.
 *
 * WHY THIS TABLE HAS NO RLS (corrected design — an earlier draft of
 * this checkpoint gave this table FORCE ROW LEVEL SECURITY with a
 * two-hop self-lookup bootstrap; that draft is the confirmed defect
 * this note replaces, not a historical footnote to preserve — see
 * ClientPortalAuthenticationTest's own docblock for the full empirical
 * reproduction that caught it):
 *   - This table is exactly `users`' own role, one level down: a
 *     global credential/identity table, not a business-data table.
 *     Compare `users`' own "a global identity table... firm-specific
 *     staff membership itself lives in firm_users, a DirectTenant
 *     table" note in
 *     RowLevelSecurityCoverageMappingService::FULL_TABLE_INVENTORY_EXTRA['users'].
 *     `client_portal_users` is the client-side mirror of exactly that
 *     split: `users` (no RLS, System) / `firm_users` (RLS, real
 *     membership) versus `client_portal_users` (no RLS, System) /
 *     `Client` + `client_portal_matter_grants` (RLS, real tenant
 *     boundary).
 *   - Login and password reset are, by definition, the moment BEFORE
 *     any tenant context can exist — `Auth::guard('client')->attempt()`
 *     (`EloquentUserProvider::retrieveByCredentials()`) and
 *     `Password::broker('client_portal_users')->sendResetLink()/reset()`
 *     must find this table's row BY EMAIL with no `app.current_firm_id`
 *     and no known `id` at all. A FORCE RLS policy here — any shape,
 *     including a self-lookup carve-out scoped by an already-known id
 *     — cannot satisfy a lookup that exists precisely to discover that
 *     id in the first place. The two-hop bootstrap this checkpoint
 *     originally shipped correctly solved "how does an ALREADY
 *     AUTHENTICATED portal user discover their own client_id" but never
 *     addressed the pre-authentication, look-up-by-email moment — it
 *     could not, structurally, since that moment has no context of any
 *     kind to hand to a policy.
 *   - Removing RLS here does NOT weaken any real tenant boundary. The
 *     data a client's login identity can actually reach — their own
 *     `Client` record, the matters they're granted visibility into —
 *     remains gated exactly as before: `Client` is still
 *     BelongsToTenant + FORCE-RLS protected (`clients_tenant_isolation`
 *     + `clients_self_lookup`, both unchanged), and
 *     `client_portal_matter_grants` is still FORCE-RLS protected with
 *     its own direct `firm_id` column. This table carries no matter
 *     data, no financial evidence, no firm-scoped business data of any
 *     kind — only a credential shell (email/password/uuid/is_active)
 *     pointing at exactly one `Client` row via a unique, cascade-deleted
 *     FK. Reading a `client_portal_users` row alone (even every row in
 *     the table) never grants access to any other RLS-protected table;
 *     that boundary is enforced entirely downstream, by `clients`' own
 *     policies.
 *   - `client_portal_password_reset_tokens` (the migration immediately
 *     after this one) already has no RLS for the identical reason —
 *     this table's design is now brought into line with that one and
 *     with the stock `password_reset_tokens`/`users` pair, rather than
 *     being the one inconsistent outlier.
 *
 * `email` is a SEPARATE column from `clients.email` (judgment call
 * §2.7.a of the design doc) — `clients.email` is business/contact data,
 * mutable by firm staff via ordinary Client CRUD at any time, unrelated
 * to authentication; coupling the login identifier to it would let a
 * staff-side contact-info edit silently change a client's login
 * username. This mirrors how `users.email` (firm-staff login) is
 * already a wholly separate concept from `clients.email` in the
 * existing schema.
 *
 * `two_factor_secret`/`two_factor_recovery_codes`/
 * `two_factor_confirmed_at` are reserved-only this checkpoint (MFA is
 * deliberately not enabled for the Client Portal — see
 * ClientPortalPanelProvider's own docblock) — columns present so a
 * future phase can enable MFA without a schema migration, mirroring how
 * `platform_admins`' own MFA columns already existed before Phase 7
 * wired them.
 *
 * Registry classification: `System` in
 * RowLevelSecurityCoverageMappingService::FULL_TABLE_INVENTORY_EXTRA
 * (App\Services\RowLevelSecurityCoverageMappingService), identical
 * treatment to `users`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_portal_users', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('client_id')->unique()->constrained('clients')->cascadeOnDelete();

            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();

            $table->boolean('is_active')->default(true);
            $table->timestamp('last_login_at')->nullable();

            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_portal_users');
    }
};
