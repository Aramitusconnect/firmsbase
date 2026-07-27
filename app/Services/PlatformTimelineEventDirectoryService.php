<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Models\TimelineEvent;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * PlatformTimelineEventDirectoryService — Phase 4 (FirmsVault Platform
 * Admin Control Center, "Operations, Governance, Support, and
 * Configuration"), Governance category, Audit Logs module. The
 * cross-firm read path behind AuditLogResource.
 *
 * Distinct from, and does NOT duplicate, Phase 1's
 * PlatformSecurityDashboardService::recentSecurityEvents() — that
 * method reads `security_events` (narrow: platform-admin action
 * auditing only — support access, MFA, high-risk changes, integration
 * oversight). This service reads `timeline_events` (broad: general
 * firm-business-activity trail — matter opening, payments, document
 * chases, key destruction, invoice drafting, conflict checks, webhook
 * replays, and 20+ other call sites — see TimelineEventRecorder's own
 * docblock: "the ONLY write path into timeline_events," with no
 * read/query method of its own). This class is genuinely new read code,
 * modeled directly on recentSecurityEvents()'s own per-firm-loop +
 * merge + re-sort/re-slice shape (see that method's docblock for the
 * full top-K-per-partition-then-merge correctness argument, which
 * applies identically here).
 *
 * Architectural constraint (identical to every other cross-firm
 * directory service in this mission — see
 * PlatformFirmUserDirectoryService's own docblock, the original
 * template): `timeline_events` carries permanent FORCE ROW LEVEL
 * SECURITY (database/migrations/2026_08_25_930033_force_rls_on_timeline_events_table.php)
 * with a SINGLE-CLAUSE policy and NO null-firm_id-visible branch (unlike
 * health_checks/backup_restore_tests/incident_events in the Operations
 * category) — there is no policy letting any session read across every
 * firm's rows at once, and this application's runtime database role is
 * never granted BYPASSRLS. The only architecturally-sound way to build
 * a cross-firm list is the same per-firm-loop-under-runWithFirmContext()
 * pattern every prior phase's own directory service uses.
 *
 * Known, deliberate performance trade-off (flagged, not hidden): this
 * is O(number of firms) queries per call, capped per-firm via
 * PER_FIRM_LIMIT so a single firm's backlog cannot dominate or unbound
 * the merged result — identical trade-off to
 * PlatformIntegrationCrossFirmDirectoryService's own disclosure. If a
 * firm filter narrows to one specific firm, the loop covers exactly
 * that firm.
 *
 * Redaction discipline: `metadata_json` is reviewed and returned
 * as-is, NOT stripped — a direct review of every current
 * TimelineEventRecorder::record() call site across the codebase (20+
 * sites: PaymentPlanService, ManualPaymentService, DocumentChaseService,
 * MatterOpeningService, InvoiceDraftingService, ConflictCheckService,
 * KeyDestructionExecutionService, WebhookReplayService,
 * LeadConversionService, PaymentPlanInstallmentService,
 * PaymentPlanDunningService, PaymentApplicationService, and the
 * Integration-domain event sites) confirms every metadata payload is
 * composed exclusively of internal numeric IDs, enum/status values, and
 * short classification strings (e.g. 'payment_id', 'amount_cents',
 * 'reason' as a short business-rule label like a rejection reason
 * category — never a raw exception message, credential, token, or
 * document content). `event_type`/`metadata_json` are both
 * caller-supplied plain strings/arrays with no schema enforced at the
 * model layer (TimelineEvent's own docblock: "event_type is a plain
 * string ... this table spans every future phase"), so this is a
 * point-in-time review, not a structural guarantee — any FUTURE caller
 * of TimelineEventRecorder::record() must keep this same discipline
 * (no raw exception text, no secret, no full document/PII payload in
 * metadata); this class does not, and cannot, enforce that for callers
 * outside the Governance category. Subject/actor are shown only as
 * type+id (never a hydrated, deeper record), mirroring
 * recentSecurityEvents()'s own "firm name, not the row's contents"
 * discipline.
 */
final class PlatformTimelineEventDirectoryService
{
    private const PER_FIRM_LIMIT = 200;

    private const COLUMNS = [
        'id', 'uuid', 'subject_type', 'subject_id', 'event_type',
        'actor_type', 'actor_id', 'occurred_at', 'metadata_json', 'created_at',
    ];

    public function __construct(
        private readonly PlatformStaffAccessPolicyService $accessPolicy,
        private readonly TenantContextService $tenantContext = new TenantContextService,
    ) {}

    public function assertCanAccess(PlatformAdmin $admin): void
    {
        $decision = $this->accessPolicy->canAccessGovernance($admin);

        if (! $decision->allowed) {
            throw new RuntimeException($decision->reason ?? 'Not permitted to access governance data.');
        }
    }

    /**
     * @param  array{firm_uuid?: ?string, event_type?: ?string, from?: ?string, to?: ?string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function list(PlatformAdmin $admin, array $filters = [], int $limit = 100): Collection
    {
        $this->assertCanAccess($admin);

        $eventType = $filters['event_type'] ?? null;
        $from = $filters['from'] ?? null;
        $to = $filters['to'] ?? null;

        $rows = collect();

        foreach ($this->firmsForFilter($filters['firm_uuid'] ?? null) as $firm) {
            $events = $this->tenantContext->runWithFirmContext($firm, fn () => TimelineEvent::query()
                ->when($eventType !== null && $eventType !== '', fn ($q) => $q->where('event_type', 'like', '%'.$eventType.'%'))
                ->when($from !== null, fn ($q) => $q->where('occurred_at', '>=', $from))
                ->when($to !== null, fn ($q) => $q->where('occurred_at', '<=', $to))
                ->orderByDesc('occurred_at')
                ->orderByDesc('id')
                ->limit(self::PER_FIRM_LIMIT)
                ->get(self::COLUMNS));

            foreach ($events as $event) {
                $rows->push($this->toRow($firm, $event));
            }
        }

        return $this->sortDeterministically($rows)->take($limit)->values();
    }

    public function find(PlatformAdmin $admin, Firm $firm, int $id): ?array
    {
        $this->assertCanAccess($admin);

        $event = $this->tenantContext->runWithFirmContext($firm, fn () => TimelineEvent::query()
            ->where('id', $id)
            ->first(self::COLUMNS));

        return $event === null ? null : $this->toRow($firm, $event);
    }

    /**
     * @return array<string, mixed>
     */
    private function toRow(Firm $firm, TimelineEvent $event): array
    {
        return [
            'id' => $event->id,
            'uuid' => $event->uuid,
            'firm_uuid' => $firm->uuid,
            'firm_name' => $firm->name,
            'event_type' => $event->event_type,
            'subject_type' => $event->subject_type,
            'subject_id' => $event->subject_id,
            'actor_type' => $event->actor_type,
            'actor_id' => $event->actor_id,
            'occurred_at' => $event->occurred_at,
            'metadata_json' => $event->metadata_json,
            'created_at' => $event->created_at,
        ];
    }

    /**
     * @return Collection<int, Firm>
     */
    private function firmsForFilter(?string $firmUuid): Collection
    {
        return Firm::query()
            ->when($firmUuid !== null, fn ($q) => $q->where('uuid', $firmUuid))
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    /**
     * Deterministic, id-tie-broken descending sort — occurred_at is a
     * plain timestamp column (whole-second precision), so two events
     * landing in the same second within/across firms is a real
     * possibility; id is globally unique/monotonic across every firm.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function sortDeterministically(Collection $rows): Collection
    {
        $items = $rows->all();

        usort($items, function (array $a, array $b): int {
            $aTime = $a['occurred_at']?->timestamp ?? 0;
            $bTime = $b['occurred_at']?->timestamp ?? 0;

            return $bTime <=> $aTime ?: $b['id'] <=> $a['id'];
        });

        return collect($items)->values();
    }
}
