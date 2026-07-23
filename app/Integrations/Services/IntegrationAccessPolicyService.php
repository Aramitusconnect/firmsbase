<?php

declare(strict_types=1);

namespace App\Integrations\Services;

use App\Enums\FirmUserRole;
use App\Models\FirmUser;
use App\Services\TimelineEventRecorder;
use RuntimeException;

/**
 * IntegrationAccessPolicyService — the non-financial-tier integration
 * role gate (checkpoint-00-final-specification.md §17;
 * architecture.md §4's permission-tiering table). Covers every
 * non-financial provider category (Google, Microsoft, Dropbox/
 * OneDrive/SharePoint, and this mission's internal `test` provider).
 *
 * Mirrors WebhookAccessPolicyService's exact shape
 * (app/Services/WebhookAccessPolicyService.php) — a single-tier
 * management gate, no requester/approver split, since architecture.md
 * §4 draws that dual-approval distinction only for the FINANCIAL tier
 * (see FinancialIntegrationAccessPolicyService, a deliberately
 * separate class, never merged with this one behind an
 * `if ($isFinancial)` branch, per the frozen spec's explicit
 * instruction).
 *
 * Role ceilings, per architecture.md §4 (non-financial column):
 *   - Connect / configure / disconnect: FirmOwner, Attorney only.
 *   - View connection/health/activity: FirmOwner, Attorney, Paralegal,
 *     LegalAssistant.
 *   - View usage/billing impact: FirmOwner, BillingStaff — identical
 *     ceiling in both the financial and non-financial columns of
 *     architecture.md §4's table, so it lives here rather than being
 *     duplicated in the financial-tier service.
 *
 * Role-tier ceilings may only be NARROWED by policy, never widened by
 * entitlement (frozen rule, checkpoint-00-final-specification.md §17)
 * — Receptionist never appears in any allowlist below, full stop.
 */
class IntegrationAccessPolicyService
{
    private const MANAGEMENT_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
    ];

    private const VIEW_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
        FirmUserRole::Paralegal,
        FirmUserRole::LegalAssistant,
    ];

    private const USAGE_VIEW_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::BillingStaff,
    ];

    public function __construct(private readonly TimelineEventRecorder $events)
    {
    }

    public function canView(FirmUserRole $role): bool
    {
        return in_array($role, self::VIEW_ROLES, true);
    }

    public function canConnect(FirmUserRole $role): bool
    {
        return in_array($role, self::MANAGEMENT_ROLES, true);
    }

    public function canConfigure(FirmUserRole $role): bool
    {
        return in_array($role, self::MANAGEMENT_ROLES, true);
    }

    public function canDisconnect(FirmUserRole $role): bool
    {
        return in_array($role, self::MANAGEMENT_ROLES, true);
    }

    public function canViewUsage(FirmUserRole $role): bool
    {
        return in_array($role, self::USAGE_VIEW_ROLES, true);
    }

    /**
     * Checkpoint 9 addition (frozen design §6/§9): management-tier
     * ceiling, matching connect/configure/disconnect's existing ceiling
     * — triggering outbound provider traffic and consuming rate-limit
     * budget is not a "view"-level action. Required by Checkpoint 10's
     * manual-sync-dispatch 11-step sequence (frozen design §11, step 5);
     * this checkpoint ships the method, the future controller/dispatch
     * action that calls it is Checkpoint 10 scope.
     */
    public function canSync(FirmUserRole $role): bool
    {
        return in_array($role, self::MANAGEMENT_ROLES, true);
    }

    public function assertCanView(FirmUser $actor): void
    {
        if (! $this->canView($actor->role)) {
            $this->recordDenied($actor, 'view');

            throw new RuntimeException(
                'Only FirmOwner, Attorney, Paralegal, or LegalAssistant may view this integration connection.'
            );
        }
    }

    public function assertCanConnect(FirmUser $actor): void
    {
        if (! $this->canConnect($actor->role)) {
            $this->recordDenied($actor, 'connect');

            throw new RuntimeException('Only FirmOwner or Attorney may connect a non-financial integration.');
        }
    }

    public function assertCanConfigure(FirmUser $actor): void
    {
        if (! $this->canConfigure($actor->role)) {
            $this->recordDenied($actor, 'configure');

            throw new RuntimeException('Only FirmOwner or Attorney may configure a non-financial integration.');
        }
    }

    public function assertCanDisconnect(FirmUser $actor): void
    {
        if (! $this->canDisconnect($actor->role)) {
            $this->recordDenied($actor, 'disconnect');

            throw new RuntimeException('Only FirmOwner or Attorney may disconnect a non-financial integration.');
        }
    }

    public function assertCanViewUsage(FirmUser $actor): void
    {
        if (! $this->canViewUsage($actor->role)) {
            $this->recordDenied($actor, 'view_usage');

            throw new RuntimeException('Only FirmOwner or BillingStaff may view integration usage/billing impact.');
        }
    }

    public function assertCanSync(FirmUser $actor): void
    {
        if (! $this->canSync($actor->role)) {
            $this->recordDenied($actor, 'sync');

            throw new RuntimeException('Only FirmOwner or Attorney may trigger a manual sync.');
        }
    }

    /**
     * Checkpoint 9 addition (frozen design §3, §6):
     * `integration_governance.action_denied`, fired on every
     * `assertCan*()` denial in this class. TimelineEventRecorder
     * requires a non-null Firm — `$actor->firm` is always resolvable
     * (every FirmUser belongs to exactly one firm) and this method is
     * only ever reached from within an already-active tenant context
     * (every existing caller of this class's assertCan*() methods
     * already wraps its call in TenantContextService::runWithFirmContext()).
     *
     * Passes `independentOfAmbientTransaction: true` — every assertCan*()
     * caller throws in the very next statement after this call, and
     * every real call site of assertCan*() runs inside
     * runWithFirmContext()'s DB::transaction() closure. Without this
     * flag, that thrown exception rolls back the ambient transaction
     * and silently discards this very row along with it, every time
     * (see TimelineEventRecorder::record()'s own docblock).
     */
    private function recordDenied(FirmUser $actor, string $action): void
    {
        $this->events->record($actor->firm, 'integration_governance.action_denied', null, $actor->user, [
            'action' => $action,
            'role' => $actor->role->value,
            'policy_service' => self::class,
        ], independentOfAmbientTransaction: true);
    }
}
