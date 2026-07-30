<?php

declare(strict_types=1);

namespace App\Integrations\Services;

use App\Enums\FirmUserRole;
use App\Models\FirmUser;
use App\Services\TimelineEventRecorder;
use RuntimeException;

/**
 * FinancialIntegrationAccessPolicyService — the financial-tier
 * integration role gate (checkpoint-00-final-specification.md §17).
 * Covers the financial provider category; Plaid is the only live
 * financial provider in this codebase (LawPay/Stripe/QuickBooks are
 * named in the original spec's category definition but are explicitly
 * NOT implemented — see App\Integrations\Enums\ProviderKey, which has
 * exactly four cases: Test, Microsoft365, GoogleWorkspace, Plaid).
 *
 * Citation corrected in Checkpoint 8.2: an earlier version of this line
 * also cited "architecture.md §4's permission-tiering table." No such
 * table exists — docs/integrations/architecture.md §4 is titled "Known
 * duplicate-looking abstractions (intentional, not accidental)" and
 * contains no permission tiering at all. The live, authoritative role
 * ceilings are the constants in this class itself.
 *
 * CORRECTED (Checkpoint 8.2, after an independent review found this
 * docblock materially inaccurate on two counts):
 *
 * (1) This class is NO LONGER SCAFFOLDING. An earlier version of this
 * docblock declared "*** PURE SCAFFOLDING THIS CHECKPOINT — NO LIVE
 * FINANCIAL PROVIDER OR CREDENTIAL EXISTS YET ***" and "No code path in
 * this mission calls or reaches this class." Both were true when written
 * (Checkpoint 3, before any financial provider existed) and are FALSE as
 * of the FirmsVault Live Integrations mission: Plaid is a live,
 * registered, seeded `financial`-category provider (see
 * database/migrations/2026_09_24_180002_seed_plaid_integration_provider_catalog_entry.php,
 * `'category' => 'financial'`), and this class is genuinely reached from
 * the Plaid Firm-panel pages/resources/widgets, the Financial Evidence
 * Livewire panels, ProviderLiveBalanceConfirmationService, and
 * ProviderBillableCallPipeline's own step-1 actor authorization. Treat
 * every ceiling below as LIVE, enforced policy — not aspirational
 * scaffolding.
 *
 * (2) THE DUAL-APPROVAL SCOPE CLAIM WAS WRONG. An earlier version
 * asserted that "architecture.md §4 requires the identical dual-approval
 * discipline for financial-tier connect/disconnect/credential-rotation/
 * conflict-resolution actions," quoting `architecture.md §4: "Same
 * (symmetric, not weaker)"`. That quoted text does not exist anywhere in
 * docs/integrations/architecture.md, in any version in this
 * repository's history — the citation was never real, and §4 of that
 * document is about an unrelated topic ("Known duplicate-looking
 * abstractions"). The AUTHORITATIVE, contemporaneous design record for
 * what actually shipped is checkpoint4-final-report.md §5-§6 plus
 * App\Integrations\Enums\FinancialAccountClassification's own binding
 * sensitive-transition list, and both scope two-person approval
 * NARROWLY:
 *
 *   - Dual approval (assertDistinctApprovers()) IS required for
 *     SENSITIVE ACCOUNT RECLASSIFICATION only — a transition into or out
 *     of TrustIolta, a TrustIolta account replacement, a second
 *     concurrent trust-account connection, or a SettlementDestination
 *     change. Enforced for real by
 *     App\Integrations\Services\FinancialAccountReclassificationService,
 *     the sole PRODUCTION caller of assertDistinctApprovers() on this
 *     class (PlaidReclassificationApprovalsPage only mirrors the rule in
 *     its UX layer and delegates the real enforcement to that service;
 *     the only other direct callers are tests).
 *   - Dual approval is NOT required for ordinary provider connection
 *     lifecycle (connect / reconnect / disconnect / credential
 *     rotation). Those are single-actor actions gated by the role
 *     ceilings below. This is a deliberate design boundary, not an
 *     unenforced control: a FirmIntegration connection strictly
 *     PRECEDES bank-account discovery, so no account classification
 *     even exists at connect/disconnect time for a
 *     classification-sensitivity rule to be evaluated against. The
 *     schema enforces that ordering: `classification` lives on
 *     `financial_evidence_bank_accounts`, whose rows are FK-bound to
 *     `firm_integrations(firm_id, id)` and therefore cannot exist before
 *     the connection does.
 *
 * Deliberately a SEPARATE class from IntegrationAccessPolicyService —
 * never one merged class with an `if ($isFinancial)` branch (frozen
 * spec, §17). assertDistinctApprovers() below still mirrors
 * TrustAccessPolicyService's real, proven shape
 * (app/Services/TrustAccessPolicyService.php) rather than inventing a
 * new pattern — note that the trust-domain precedent is itself scoped to
 * one specific high-risk action class (TrustHighRiskAdjustmentService),
 * consistent with the narrow reclassification-only scope above.
 *
 * Live role ceilings:
 *   - Request a sensitive reclassification: FirmOwner, Attorney,
 *     BillingStaff (see REQUESTER_ROLES).
 *   - Approve a sensitive reclassification: FirmOwner or Attorney ONLY,
 *     and the second approver must be a DIFFERENT firm user than the
 *     first (see APPROVER_ROLES + assertDistinctApprovers()).
 *   - Connect / reconnect / disconnect / credential rotation:
 *     single-actor, bounded by the view ceiling below.
 *   - View health/activity: FirmOwner, Attorney, BillingStaff ONLY —
 *     narrower than the non-financial tier's view ceiling (which also
 *     includes Paralegal/LegalAssistant).
 *   - View usage/billing impact: FirmOwner, BillingStaff — identical to
 *     the non-financial tier; see
 *     IntegrationAccessPolicyService::assertCanViewUsage(), not
 *     duplicated here.
 *
 * Role-tier ceilings may only be NARROWED by policy, never widened by
 * entitlement (frozen rule) — Paralegal, LegalAssistant, and
 * Receptionist NEVER receive any financial-tier integration
 * permission, full stop, regardless of any future entitlement grant.
 */
class FinancialIntegrationAccessPolicyService
{
    private const REQUESTER_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
        FirmUserRole::BillingStaff,
    ];

    private const APPROVER_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
    ];

    private const VIEW_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
        FirmUserRole::BillingStaff,
    ];

    public function __construct(private readonly TimelineEventRecorder $events)
    {
    }

    public function canRequest(FirmUserRole $role): bool
    {
        return in_array($role, self::REQUESTER_ROLES, true);
    }

    public function canApprove(FirmUserRole $role): bool
    {
        return in_array($role, self::APPROVER_ROLES, true);
    }

    public function canView(FirmUserRole $role): bool
    {
        return in_array($role, self::VIEW_ROLES, true);
    }

    public function assertCanRequest(FirmUser $actor): void
    {
        if (! $this->canRequest($actor->role)) {
            $this->recordDenied($actor, 'request');

            throw new RuntimeException(
                'Only FirmOwner, Attorney, or BillingStaff may request a financial-tier integration action.'
            );
        }
    }

    public function assertCanApprove(FirmUser $actor): void
    {
        if (! $this->canApprove($actor->role)) {
            $this->recordDenied($actor, 'approve');

            throw new RuntimeException(
                'Only FirmOwner or Attorney may approve a financial-tier integration action. '.
                'BillingStaff may request but not approve.'
            );
        }
    }

    public function assertCanView(FirmUser $actor): void
    {
        if (! $this->canView($actor->role)) {
            $this->recordDenied($actor, 'view');

            throw new RuntimeException(
                'Only FirmOwner, Attorney, or BillingStaff may view a financial-tier integration connection.'
            );
        }
    }

    /**
     * Connect, disconnect, credential rotation, and monetary conflict
     * resolution on a financial-tier connection all require two
     * DIFFERENT approvers, both from {FirmOwner, Attorney} — mirrors
     * TrustAccessPolicyService::assertDistinctApprovers() exactly.
     *
     * Checkpoint 9 addition (frozen design §3, agent-9h-architecture-
     * security-review.md §2.2): fires
     * `integration_governance.distinct_approver_violation` on the
     * failure path (same-actor violation) and
     * `integration_governance.distinct_approvers_confirmed` on the
     * success path — a dual-approval control that only audits its
     * failure mode leaves no evidence trail for the compliance-relevant
     * positive case ("who were the two approvers"). This is a narrow,
     * one-event addition, NOT a blanket "action granted" event (which
     * 9B correctly rejected as duplicative).
     */
    public function assertDistinctApprovers(FirmUser $first, FirmUser $second): void
    {
        $this->assertCanApprove($first);
        $this->assertCanApprove($second);

        if ($first->id === $second->id) {
            $this->events->record($first->firm, 'integration_governance.distinct_approver_violation', null, $first->user, [
                'first_approver_firm_user_id' => $first->id,
                'second_approver_firm_user_id' => $second->id,
            ], independentOfAmbientTransaction: true);

            throw new RuntimeException(
                'The second approver must be a different firm user than the first approver.'
            );
        }

        $this->events->record($first->firm, 'integration_governance.distinct_approvers_confirmed', null, $first->user, [
            'first_approver_firm_user_id' => $first->id,
            'second_approver_firm_user_id' => $second->id,
        ]);
    }

    /**
     * Checkpoint 9 addition (frozen design §3, §6):
     * `integration_governance.action_denied`, fired on every
     * `assertCan*()` denial in this class. Mirrors
     * `IntegrationAccessPolicyService::recordDenied()` exactly,
     * including passing `independentOfAmbientTransaction: true` for
     * the same reason (see that method's docblock and
     * TimelineEventRecorder::record()'s own docblock).
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
