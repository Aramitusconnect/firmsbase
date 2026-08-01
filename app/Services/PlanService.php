<?php

namespace App\Services;

use App\Enums\PlanStatus;
use App\Models\Firm;
use App\Models\FirmLicense;
use App\Models\Plan;
use App\Models\PlatformAdmin;
use App\Models\PlatformSubscription;
use BackedEnum;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * PlanService — the only place Plan rows are created or have their
 * lifecycle status changed. Plans are global reference/commercial data
 * (no firm_id), edited by platform admins only.
 *
 * Phase 3 (FirmsVault Platform Admin Control Center, "Billing and
 * Commercial Administration") addition: activate()/archive() now
 * accept an optional PlatformAdmin $actor and, when one is supplied,
 * record a PlatformAdminAuditEventRecorder::recordPlatformEvent() row
 * (the firm-less variant — a Plan is not tied to one firm). When
 * $actor is null (every existing caller — no app-level call site
 * currently passes one; only tests call these methods directly today)
 * behavior is byte-for-byte unchanged from before this addition.
 *
 * FIRMSVAULT — STAGING ADMIN STABILIZATION addition: create()/update()
 * now carry real validation and auditing instead of being callable
 * only out-of-band (this pass's own defect list: "PlanResource has no
 * supported Create Plan action"). `code` uniqueness is checked here
 * (case-insensitive) ahead of the DB unique constraint so a duplicate
 * submission fails with a clean InvalidArgumentException rather than a
 * raw SQL error reaching the Filament form. update() refuses to change
 * price_cents/billing_interval/code once at least one FirmLicense or
 * PlatformSubscription already references the plan — neither of those
 * models stores an independent price snapshot of its own (only
 * plan_id), so silently changing a plan's financial terms after firms
 * are already on it would retroactively rewrite what those firms are
 * understood to be paying. Every other field (name, description,
 * support_access_level, trial_days, trial_requires_card) stays freely
 * editable at any time — this is a narrow financial-terms lock, not a
 * general "plan becomes frozen once used" rule.
 */
class PlanService
{
    private const AUDIT_CATEGORY = 'platform_billing';

    /**
     * Fields considered financial terms — locked once a plan is in
     * use. See this class's own docblock for why.
     */
    private const LOCKED_ONCE_IN_USE = ['price_cents', 'billing_interval', 'code'];

    public function __construct(
        private readonly TenantContextService $tenantContext,
        private readonly PlatformAdminAuditEventRecorder $auditRecorder = new PlatformAdminAuditEventRecorder,
    ) {}

    public function create(array $attributes, ?PlatformAdmin $actor = null): Plan
    {
        $code = $attributes['code'] ?? null;

        if (! is_string($code) || trim($code) === '') {
            throw new InvalidArgumentException('A plan code is required.');
        }

        $this->assertCodeIsUnique($code);

        $plan = DB::transaction(fn (): Plan => Plan::create(array_merge(
            ['status' => PlanStatus::Draft, 'is_active' => true],
            $attributes,
        )));

        if ($actor !== null) {
            $this->auditRecorder->recordPlatformEvent(
                $actor,
                'plan_created',
                self::AUDIT_CATEGORY,
                [
                    'plan_id' => $plan->id,
                    'code' => $plan->code,
                    'status' => $plan->status->value,
                ],
            );
        }

        return $plan;
    }

    public function update(Plan $plan, array $attributes, ?PlatformAdmin $actor = null): Plan
    {
        $lockedFieldsChanged = [];

        if ($this->isInUse($plan)) {
            foreach (self::LOCKED_ONCE_IN_USE as $field) {
                if (! array_key_exists($field, $attributes)) {
                    continue;
                }

                $incoming = $attributes[$field] instanceof BackedEnum ? $attributes[$field]->value : $attributes[$field];
                $current = $plan->{$field} instanceof BackedEnum ? $plan->{$field}->value : $plan->{$field};

                if ($incoming !== $current) {
                    $lockedFieldsChanged[] = $field;
                }
            }
        }

        if ($lockedFieldsChanged !== []) {
            throw new InvalidArgumentException(
                'Cannot change '.implode(', ', $lockedFieldsChanged).' — this plan already has at least one '
                .'assigned firm license or platform subscription, and financial terms are locked once a plan '
                .'is in use.'
            );
        }

        if (array_key_exists('code', $attributes)) {
            $newCode = $attributes['code'];

            if (! is_string($newCode) || trim($newCode) === '') {
                throw new InvalidArgumentException('A plan code is required.');
            }

            if (strcasecmp($newCode, $plan->code ?? '') !== 0) {
                $this->assertCodeIsUnique($newCode, excludingPlanId: $plan->id);
            }
        }

        $updated = DB::transaction(fn (): Plan => tap($plan)->update($attributes)->fresh());

        if ($actor !== null) {
            $this->auditRecorder->recordPlatformEvent(
                $actor,
                'plan_updated',
                self::AUDIT_CATEGORY,
                [
                    'plan_id' => $updated->id,
                    'changed_fields' => array_keys($attributes),
                ],
            );
        }

        return $updated;
    }

    public function activate(Plan $plan, ?PlatformAdmin $actor = null): Plan
    {
        $activated = tap($plan)->update(['status' => PlanStatus::Active])->fresh();

        if ($actor !== null) {
            $this->auditRecorder->recordPlatformEvent(
                $actor,
                'plan_activated',
                self::AUDIT_CATEGORY,
                [
                    'plan_id' => $activated->id,
                    'resulting_status' => $activated->status->value,
                ],
            );
        }

        return $activated;
    }

    public function archive(Plan $plan, ?PlatformAdmin $actor = null): Plan
    {
        $archived = tap($plan)->update(['status' => PlanStatus::Archived, 'is_active' => false])->fresh();

        if ($actor !== null) {
            $this->auditRecorder->recordPlatformEvent(
                $actor,
                'plan_archived',
                self::AUDIT_CATEGORY,
                [
                    'plan_id' => $archived->id,
                    'resulting_status' => $archived->status->value,
                ],
            );
        }

        return $archived;
    }

    /**
     * `firm_licenses` carries FORCE RLS (firm-scoped) — a plain
     * cross-firm ->where('plan_id', ...)->exists() call would run with
     * no app.current_firm_id set and silently see zero rows regardless
     * of real data, exactly the RLS-visibility pitfall
     * PlatformFirmUserDirectoryService::countAll() already documents
     * and works around with a per-firm runWithFirmContext() loop — the
     * same pattern is reused here. `platform_subscriptions` carries no
     * RLS at all (Global, keyed to billing_account_id not firm_id —
     * see that model's own docblock), so it is queried directly.
     */
    private function isInUse(Plan $plan): bool
    {
        if (PlatformSubscription::query()->where('plan_id', $plan->id)->exists()) {
            return true;
        }

        foreach (Firm::query()->cursor() as $firm) {
            $hasLicense = $this->tenantContext->runWithFirmContext(
                $firm,
                fn (): bool => FirmLicense::query()->where('plan_id', $plan->id)->exists(),
            );

            if ($hasLicense) {
                return true;
            }
        }

        return false;
    }

    private function assertCodeIsUnique(string $code, ?int $excludingPlanId = null): void
    {
        $query = Plan::query()->whereRaw('lower(code) = ?', [strtolower($code)]);

        if ($excludingPlanId !== null) {
            $query->whereKeyNot($excludingPlanId);
        }

        if ($query->exists()) {
            throw new InvalidArgumentException("A plan with code \"{$code}\" already exists.");
        }
    }
}
