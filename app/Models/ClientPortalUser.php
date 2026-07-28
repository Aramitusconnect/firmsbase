<?php

namespace App\Models;

use App\Enums\ClientPortalStatus;
use App\Models\Concerns\HasPublicUuid;
use App\Notifications\ClientPortalResetPasswordNotification;
use App\Services\TenantContextService;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * ClientPortalUser — Checkpoint 4 ("Plaid financial evidence add-on"),
 * Client Portal authentication foundation
 * (checkpoint4-combined-design.md §5;
 * checkpoint4-design-matter-and-client-portal.md §2.1). A distinct
 * identity from `Client`'s own business record — deliberately NOT
 * `Client` itself made Authenticatable:
 *
 *   - `Client` is `BelongsToTenant` + FORCE-RLS-protected. A
 *     BelongsToTenant-scoped model requires an ambient firm tenant
 *     context to be resolved BEFORE any query against it succeeds —
 *     but authentication is precisely the bootstrap moment where no
 *     context exists yet. Overloading `Client` itself as the
 *     Authenticatable model would require either a permanent
 *     self-lookup RLS carve-out on `clients` for every future caller,
 *     or a login flow that resolves the firm out-of-band before the
 *     password check (which defeats the point of login in the first
 *     place).
 *   - `FirmUserRole`'s own docblock is explicit and load-bearing:
 *     "clients are never firm_users; when the client phase lands,
 *     clients get their own identity/access model, not a FirmUserRole
 *     value." Neither `User` (firm-staff-shaped) nor `PlatformAdmin`
 *     (cross-firm-shaped) is the right template to copy verbatim for a
 *     client identity, which is inherently firm- and matter-scoped.
 *
 * `client_id` is a unique FK (1:1 with `Client`), `cascadeOnDelete()`.
 * Has NO `firm_id` column of its own — exactly `MatterAssignment`'s
 * established "isolation is transitive through the parent" pattern:
 * isolation here is transitive through `client_id -> clients.firm_id`.
 *
 * `email` is deliberately a SEPARATE column from `Client.email` (the
 * login identifier vs. the client's business contact-record email —
 * see the create-table migration's own docblock for the full
 * reasoning).
 *
 * Sole writer: `ClientPortalService::activate()`.
 *
 * `Notifiable` is required, not decorative: `Illuminate\Auth\Passwords\CanResetPassword`'s
 * `sendPasswordResetNotification()` calls `$this->notify(...)`, which does
 * not exist without it — `Illuminate\Foundation\Auth\User` (this class's
 * base) does not include `Notifiable` itself, unlike the framework's own
 * default `App\Models\User`. Found and fixed during this checkpoint's own
 * test-writing pass: password-reset-link generation threw
 * `Error: Call to undefined method ClientPortalUser::notify()` without it.
 */
class ClientPortalUser extends Authenticatable implements FilamentUser
{
    use HasFactory, HasPublicUuid, Notifiable;

    protected $table = 'client_portal_users';

    protected $fillable = [
        'client_id',
        'email',
        'password',
        'is_active',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Overrides `CanResetPassword`'s default notification, which builds
     * its reset URL via `route('password.reset', ...)` — a route this app
     * does not have (see ClientPortalResetPasswordNotification's own
     * docblock).
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new ClientPortalResetPasswordNotification($token));
    }

    /**
     * canAccessPanel() is the sole coarse gate on Client Portal panel
     * access, mirroring PlatformAdmin::canAccessPanel()'s own
     * deliberately-narrow shape. Checks this credential's own
     * `is_active` flag AND the underlying Client's `portal_status`
     * (§2.5's "account deactivation handling" requirement — either one
     * being false denies login).
     *
     * Filament calls this method during LOGIN itself (before
     * `EstablishClientPortalTenantContext` has ever run — that
     * middleware only fires on already-authenticated subsequent
     * requests) as well as on every later request, exactly the same
     * timing problem `User::canAccessPanel()` already solves via
     * `activeFirmUser()` -> `TenantContextService::withUserContext()`.
     * No ambient firm context can be assumed here, so this reads
     * `Client` via the identical ONE-HOP self-lookup bootstrap
     * `EstablishClientPortalTenantContext` itself performs, rather than
     * relying on `$this->client` (which would silently resolve to null
     * with no context active, at login time or during MFA/other
     * pre-context checks). This is the ONLY other call site in this
     * codebase that also performs this bootstrap — it must stay in
     * lockstep with `EstablishClientPortalTenantContext::handle()`.
     *
     * CORRECTED DESIGN: an earlier draft of this checkpoint performed a
     * two-hop bootstrap here (first self-looking-up this own row via a
     * `client_portal_users_self_lookup` RLS policy, then `Client`).
     * `client_portal_users` has since been reclassified System (no RLS
     * at all, identical treatment to `users`; see that table's own
     * create-migration docblock's "WHY THIS TABLE HAS NO RLS" section),
     * so the first hop is now an ordinary, unwrapped query — only the
     * `Client` self-lookup hop remains genuinely RLS-gated.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $tenantContext = app(TenantContextService::class);
        $portalUserId = (int) $this->getAuthIdentifier();

        // client_portal_users carries no RLS (System classification,
        // identical to users) — an ordinary, unwrapped query.
        $clientId = static::query()->findOrFail($portalUserId)->client_id;

        return (bool) $tenantContext->withClientSelfLookupContext($clientId, function () use ($clientId): bool {
            $client = Client::query()->find($clientId);

            return $client !== null && $client->portal_status === ClientPortalStatus::Active;
        });
    }
}
