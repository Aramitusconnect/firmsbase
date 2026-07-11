<?php

namespace App\Services;

use App\Enums\FirmActivationEventStatus;
use App\Models\Firm;
use App\ValueObjects\ProductionReadinessResult;
use Illuminate\Support\Facades\DB;

/**
 * FirmProductionActivationService — the ONLY place a firm's
 * production-readiness is evaluated or logged. Production-readiness is
 * EVENT-DERIVED ONLY (approved decision): there is no new column on
 * firms and Phase 1's FirmActivationStatus (draft/onboarding/activated)
 * is untouched. "Production ready" is a stricter, later gate that sits
 * on top of Phase 1's existing activation, computed at read time from:
 *   1. ActivationChecklistService::unmetRequirements() (Phase 1's
 *      original gate: billing account, firm settings, usable license,
 *      checklist, tenant encryption key).
 *   2. Every one of ActivationChecklistService::PRODUCTION_READINESS_
 *      ITEMS' 12 item_keys must actually EXIST on the firm's checklist
 *      AND be complete or waived. A checklist with zero seeded items
 *      is NOT ready — hotfix 01: evaluate() previously treated "no
 *      unsatisfied items found" as ready even when no production-
 *      readiness items had been seeded at all (an empty set vacuously
 *      satisfied the old check). It now explicitly checks for missing
 *      item_keys first and blocks on them.
 *
 * Every evaluation writes a firm_activation_event (audit trail of
 * checks/blocking reasons/transitions, per project rule) — this is the
 * only write this service performs to firm_activation_events; it never
 * writes to firms itself.
 *
 * Section 39A-3L, Checkpoint 3 — firm_activation_events now has FORCE
 * ROW LEVEL SECURITY active. Tenant-context wiring, one wrap per unit of
 * work (project convention — see TenantContextService's own docblock on
 * why a nested self-wrap is unsafe):
 *   - evaluate() no longer calls its own (previously unwrapped,
 *     redundant) loadMissing(['activationChecklist.items']) — that call
 *     was deleted outright rather than wrapped, since it did nothing
 *     but poison Eloquent's relation cache ahead of
 *     ActivationChecklistService::unmetRequirements()'s own correct,
 *     context-wrapped load of the same relation (loadMissing() no-ops
 *     on a relation already "loaded," even cached as null).
 *   - recordEvaluation() (private, called from evaluate() after
 *     unmetRequirements() returns) wraps its entire body in one
 *     runWithFirmContext() call. This is a second, SEQUENTIAL wrap, not
 *     a nested one: by the time evaluate() reaches recordEvaluation(),
 *     unmetRequirements()'s own wrap has already returned and cleared —
 *     exactly like ActivationChecklistService::activate()'s pattern.
 *   - autoCompleteVerifiableItems() wraps its entire body in one
 *     runWithFirmContext() call; it calls no other already-wrapped
 *     method, so there is no nesting risk. Its existing per-item
 *     DB::transaction() stays nested inside the new outer wrap as a
 *     savepoint (safe per TenantContextService's own docblock).
 *   - isProductionReady() needs no wrap of its own: it does nothing but
 *     delegate to evaluate().
 */
class FirmProductionActivationService
{
    /**
     * The 6 item_keys that ActivationChecklistService can auto-verify
     * from existing schema (jurisdiction/practice_areas/plan_license/
     * payment_mode/ai_mode/users). The remaining 6 (firm_profile,
     * portal, email_domain, consents, templates,
     * compliance_acknowledgments) require explicit human completion —
     * there is no reliable automatic signal for "this was reviewed and
     * confirmed," so they are left for staff to mark complete via the
     * existing ActivationChecklistItem::update() mechanism.
     */
    public const AUTO_VERIFIABLE_ITEM_KEYS = [
        'jurisdiction',
        'practice_areas',
        'plan_license',
        'payment_mode',
        'ai_mode',
        'users',
    ];

    public function __construct(private ActivationChecklistService $activationChecklist)
    {
    }

    public function evaluate(Firm $firm): ProductionReadinessResult
    {
        // Deliberately no loadMissing() call here: this is fully
        // redundant with (and, worse, would poison the cache ahead of)
        // ActivationChecklistService::unmetRequirements()'s own wrapped
        // loadMissing(['activationChecklist.items', ...]) on the very
        // next line. loadMissing() no-ops on a relation that is already
        // "loaded" — even if that load happened outside any tenant
        // context and cached null — so an unwrapped call here would
        // silently prevent the correct, context-wrapped load below from
        // ever running. See this class's docblock.
        $baseUnmet = $this->activationChecklist->unmetRequirements($firm);
        $blockingReasons = $baseUnmet;

        $checklist = $firm->activationChecklist;
        $unmetItems = [];

        if (! $checklist) {
            $blockingReasons[] = 'activation_checklist_missing';
        } else {
            $existingKeys = $checklist->items()->pluck('item_key')->all();
            $requiredKeys = array_keys(ActivationChecklistService::PRODUCTION_READINESS_ITEMS);
            $missingKeys = array_values(array_diff($requiredKeys, $existingKeys));

            if (! empty($missingKeys)) {
                // hotfix 01: a checklist with none (or only some) of the
                // 12 go-live items seeded must never be treated as ready
                // just because no UNSATISFIED item was found — the
                // items have to actually exist first.
                $blockingReasons[] = 'missing production readiness checklist items';
                $unmetItems = $missingKeys;
            }

            $incompleteItems = $checklist->items()
                ->where('is_required', true)
                ->whereNull('waived_at')
                ->where('is_complete', false)
                ->pluck('item_key')
                ->all();

            $unmetItems = array_values(array_unique(array_merge($unmetItems, $incompleteItems)));
        }

        $ready = empty($blockingReasons) && empty($unmetItems);

        $this->recordEvaluation($firm, $ready, $unmetItems, $blockingReasons);

        return new ProductionReadinessResult($ready, $unmetItems, $blockingReasons);
    }

    public function isProductionReady(Firm $firm): bool
    {
        return $this->evaluate($firm)->ready;
    }

    /**
     * Auto-completes whichever of AUTO_VERIFIABLE_ITEM_KEYS are
     * genuinely satisfied by current data, logging one
     * firm_activation_event per item completed this call. Never marks
     * an item complete that isn't actually satisfied, and never
     * touches the 6 manual-only item_keys.
     */
    public function autoCompleteVerifiableItems(Firm $firm): array
    {
        // Whole-method wrap: this method calls no other method that
        // already self-wraps (it does not call evaluate() or
        // unmetRequirements()), so there is no nesting risk here. The
        // per-item DB::transaction() below stays nested inside this
        // wrap as a savepoint, per TenantContextService's own docblock.
        return (new TenantContextService())->runWithFirmContext($firm, function () use ($firm) {
            $firm->loadMissing(['activationChecklist.items', 'firmSettings', 'firmPracticeAreas', 'licenses', 'firmUsers']);

            $checklist = $firm->activationChecklist;

            if (! $checklist) {
                return [];
            }

            $checks = [
                'jurisdiction' => fn () => (bool) $firm->firmSettings?->state_jurisdiction,
                'practice_areas' => fn () => $firm->firmPracticeAreas->contains(fn ($fpa) => $fpa->is_enabled),
                'plan_license' => fn () => $firm->licenses->contains(
                    fn ($license) => ! in_array($license->license_status, [
                        \App\Enums\LicenseStatus::Cancelled,
                        \App\Enums\LicenseStatus::Expired,
                    ], true)
                ),
                'payment_mode' => fn () => (bool) $firm->firmSettings?->payment_mode,
                'ai_mode' => fn () => (bool) $firm->firmSettings?->ai_mode,
                'users' => fn () => $firm->firmUsers->contains(
                    fn ($fu) => $fu->status === \App\Enums\FirmUserStatus::Active
                ),
            ];

            $completed = [];

            foreach ($checks as $itemKey => $isSatisfied) {
                $item = $checklist->items()->where('item_key', $itemKey)->first();

                if (! $item || $item->is_complete || $item->waived_at) {
                    continue;
                }

                if ($isSatisfied()) {
                    DB::transaction(function () use ($item, $firm, $itemKey, &$completed) {
                        $item->update(['is_complete' => true, 'completed_at' => now()]);

                        \App\Models\FirmActivationEvent::create([
                            'firm_id' => $firm->id,
                            'event_type' => 'checklist_item_completed',
                            'status' => FirmActivationEventStatus::Completed,
                            'checklist_item_key' => $itemKey,
                        ]);

                        $completed[] = $itemKey;
                    });
                }
            }

            return $completed;
        });
    }

    private function recordEvaluation(Firm $firm, bool $ready, array $unmetItems, array $blockingReasons): void
    {
        // Whole-method wrap: this is a second, SEQUENTIAL wrap, not a
        // nested one — by the time evaluate() calls recordEvaluation(),
        // unmetRequirements()'s own wrap (invoked earlier in evaluate())
        // has already returned and cleared its context. Same pattern as
        // ActivationChecklistService::activate()'s second wrap.
        (new TenantContextService())->runWithFirmContext($firm, function () use ($firm, $ready, $unmetItems, $blockingReasons) {
            \App\Models\FirmActivationEvent::create([
                'firm_id' => $firm->id,
                'event_type' => 'production_readiness_evaluated',
                'status' => $ready ? FirmActivationEventStatus::Passed : FirmActivationEventStatus::Blocked,
                'blocking_reason' => $ready ? null : implode('; ', array_merge($blockingReasons, $unmetItems)),
                'metadata_json' => [
                    'unmet_items' => $unmetItems,
                    'blocking_reasons' => $blockingReasons,
                ],
            ]);

            if ($ready) {
                \App\Models\FirmActivationEvent::create([
                    'firm_id' => $firm->id,
                    'event_type' => 'production_ready',
                    'status' => FirmActivationEventStatus::Completed,
                ]);
            }
        });
    }
}
