<?php

declare(strict_types=1);

namespace App\Services\Configuration;

use App\Enums\NotificationTemplateStatus;
use App\Models\AiPolicySetting;
use App\Models\MatterType;
use App\Models\NotificationTemplate;
use App\Models\PracticeArea;
use App\Services\AiModeResolutionService;
use Illuminate\Support\Facades\DB;

/**
 * ConfigurationOverviewReadService — the read model behind the
 * Configuration Overview page. Read-only aggregation over configuration
 * data an operator can act on.
 *
 * TWO RULES SHAPE EVERY METRIC HERE
 * ---------------------------------
 * 1. NO FABRICATED ZEROS (mission section 24). Each metric carries an
 *    explicit availability state. A metric that was genuinely measured
 *    and came back zero is `available` with value 0. A metric whose
 *    source cannot be read from this page is `unavailable` with a
 *    reason, and renders as text — never as the number 0. The two are
 *    materially different claims and the console must not blur them.
 *
 * 2. NO O(FIRMS) WORK (mission section 91). Everything below is a
 *    bounded query against global, non-tenant tables, or against the
 *    globally-readable subset of a tenant table.
 *
 * WHY ENTITLEMENT METRICS ARE DELIBERATELY ABSENT
 * -----------------------------------------------
 * `firm_entitlements` carries FORCE ROW LEVEL SECURITY. From this
 * page's zero-tenant-context session, every aggregate against it
 * returns 0 — not an error. So "12 firms have overrides" and "no firm
 * has any override" are indistinguishable here, and printing the latter
 * would be a fabricated reassurance on a governance dashboard.
 *
 * Counting them truthfully requires the approved per-firm
 * runWithFirmContext() loop, which is O(number of firms) and belongs on
 * a filtered, paginated screen rather than on a summary page that
 * renders on every visit. So entitlement counts are reported as
 * unavailable with a pointer to the Entitlement Overrides screen, which
 * already performs that loop under a firm filter. This is a deliberate
 * omission with a stated reason, not an oversight.
 */
class ConfigurationOverviewReadService
{
    public function __construct(
        private readonly PracticeAreaCanonicalizationService $canonicalization = new PracticeAreaCanonicalizationService,
        private readonly NotificationTemplateContentPolicyService $contentPolicy = new NotificationTemplateContentPolicyService,
        private readonly AiPolicyDefinitionRegistry $aiRegistry = new AiPolicyDefinitionRegistry,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function metric(string $label, int $value, ?string $note = null): array
    {
        return ['label' => $label, 'value' => $value, 'available' => true, 'note' => $note];
    }

    /**
     * @return array<string, mixed>
     */
    public function unavailableMetric(string $label, string $reason): array
    {
        return ['label' => $label, 'value' => null, 'available' => false, 'note' => $reason];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function practiceAreaMetrics(): array
    {
        $duplicatePairs = $this->canonicalization->suspectedDuplicatePairs();
        $ambiguousAliases = $this->canonicalization->ambiguousAliases();

        return [
            $this->metric('Active practice areas', PracticeArea::query()->where('is_active', true)->count()),
            $this->metric('Inactive practice areas', PracticeArea::query()->where('is_active', false)->count()),
            $this->metric('Marketplace visible', PracticeArea::query()->where('is_marketplace_visible', true)->count()),
            $this->metric(
                'Suspected duplicate pairs',
                $duplicatePairs->count(),
                'Pairs whose name, code, slug or stored alias normalizes onto each other.',
            ),
            $this->metric(
                'Practice areas with stored aliases',
                PracticeArea::query()
                    ->whereNotNull('synonyms')
                    ->whereRaw('json_array_length(synonyms::json) > 0')
                    ->count(),
                'Stored only — no resolver in this codebase consults practice_areas.synonyms.',
            ),
            $this->metric(
                'Ambiguous stored aliases',
                $ambiguousAliases->count(),
                'One alias claimed by two or more practice areas — would be non-deterministic for any future resolver.',
            ),
            $this->metric(
                'Practice areas with no matter types',
                PracticeArea::query()->whereDoesntHave('matterTypes')->count(),
            ),
            $this->metric('Matter types mapped to a practice area', MatterType::query()->count()),
            // Tenant-scoped: see this class's own docblock.
            $this->unavailableMetric(
                'Firms using each practice area',
                'Tenant-scoped (FORCE RLS) — run the per-practice-area impact preview to count this across firms.',
            ),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function entitlementMetrics(): array
    {
        $reason = 'Tenant-scoped (FORCE RLS) — cannot be aggregated from a platform session without an O(firms) scan. Use Entitlement Overrides, filtered by firm.';

        return [
            $this->unavailableMetric('Firms with overrides', $reason),
            $this->unavailableMetric('Active overrides', $reason),
            $this->unavailableMetric('Temporary overrides', $reason),
            $this->unavailableMetric('Permanent overrides', $reason),
            $this->unavailableMetric('Overrides expiring soon', $reason),
            $this->unavailableMetric('Expired overrides', $reason),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function aiPolicyMetrics(): array
    {
        $stored = AiPolicySetting::query()->get(['key', 'value_json']);
        $defined = $this->aiRegistry->definitions();

        $unrecognized = $stored->reject(fn (AiPolicySetting $s): bool => $this->aiRegistry->isRecognized($s->key));
        $invalid = $stored->filter(fn (AiPolicySetting $s): bool => $this->aiRegistry->validate($s->key, $s->value_json) !== null);
        $missing = collect(array_keys($defined))->reject(fn (string $key): bool => $stored->contains('key', $key));

        return [
            $this->metric('Policies with a defined type', $stored->count() - $unrecognized->count()),
            $this->metric(
                'Stored keys not consumed by any service',
                $unrecognized->count(),
                'Present in the table but read by no service — stored data only.',
            ),
            $this->metric(
                'Values failing type validation',
                $invalid->count(),
                'A stored value whose type does not match what its consumer expects.',
            ),
            $this->metric(
                'Defined policies using their default',
                $missing->count(),
                'No row written; the consumer\'s documented absent-row behaviour applies.',
            ),
            $this->metric(
                'Firm-level AI policy overrides',
                0,
                'ai_policy_settings has no firm_id column — this table is platform-level only, so a firm override layer does not exist.',
            ),
        ];
    }

    /**
     * Notification template metrics, scoped to GLOBAL DEFAULTS.
     *
     * Firm override rows are tenant-owned; `notification_templates`
     * carries a dual-policy RLS design that makes global-default rows
     * (firm_id IS NULL) readable from a zero-context session, but firm
     * rows are not, so they are reported as unavailable rather than
     * counted as zero.
     *
     * @return list<array<string, mixed>>
     */
    public function notificationTemplateMetrics(): array
    {
        $globals = NotificationTemplate::query()->whereNull('firm_id')->get(['id', 'status', 'language', 'channel', 'subject', 'body']);

        $invalid = $globals->filter(
            fn (NotificationTemplate $t): bool => ! $this->contentPolicy->isValid($t->subject, $t->body)
        );

        return [
            $this->metric('Global defaults — active', $globals->where('status', NotificationTemplateStatus::Active)->count()),
            $this->metric('Global defaults — draft', $globals->where('status', NotificationTemplateStatus::Draft)->count()),
            $this->metric('Global defaults — archived', $globals->where('status', NotificationTemplateStatus::Archived)->count()),
            $this->metric('Languages configured', $globals->pluck('language')->unique()->count()),
            $this->metric('Channels used', $globals->pluck('channel')->unique()->count()),
            $this->metric(
                'Templates failing content validation',
                $invalid->count(),
                'Content containing executable constructs or malformed variable placeholders.',
            ),
            $this->unavailableMetric(
                'Firm template overrides',
                'Tenant-scoped (FORCE RLS) — select a firm on Notification Templates to see its overrides.',
            ),
            $this->unavailableMetric(
                'Missing required templates',
                'Not implemented — this codebase has no canonical required notification-event catalog to compare against.',
            ),
            $this->unavailableMetric(
                'Unpublished changes / version history',
                'Not implemented — notification_templates has no version column, so template versioning does not exist.',
            ),
        ];
    }

    /**
     * Real, actionable signals only. An empty list genuinely means every
     * monitored source was evaluated and none needs attention — the
     * sources evaluated are exactly the ones counted above.
     *
     * @return list<array{severity: string, title: string, detail: string}>
     */
    public function requiresAttention(): array
    {
        $items = [];

        $duplicatePairs = $this->canonicalization->suspectedDuplicatePairs();

        if ($duplicatePairs->isNotEmpty()) {
            $items[] = [
                'severity' => 'warning',
                'title' => $duplicatePairs->count().' suspected duplicate practice area pair(s)',
                'detail' => 'Examples: '.$duplicatePairs->take(3)->map(
                    fn (array $pair): string => $pair['lower']->code.' / '.$pair['higher']->code
                )->implode(', ').'. Review before any merge — merging existing data requires owner approval.',
            ];
        }

        $ambiguous = $this->canonicalization->ambiguousAliases();

        if ($ambiguous->isNotEmpty()) {
            $items[] = [
                'severity' => 'warning',
                'title' => $ambiguous->count().' ambiguous stored alias(es)',
                'detail' => 'An alias claimed by more than one practice area would make any future alias resolver non-deterministic.',
            ];
        }

        $unmapped = PracticeArea::query()->where('is_active', true)->whereDoesntHave('matterTypes')->count();

        if ($unmapped > 0) {
            $items[] = [
                'severity' => 'info',
                'title' => $unmapped.' active practice area(s) with no matter types',
                'detail' => 'Firms selecting these practice areas have no matter type to choose from.',
            ];
        }

        $invalidTemplates = NotificationTemplate::query()
            ->whereNull('firm_id')
            ->get(['id', 'key', 'subject', 'body'])
            ->filter(fn (NotificationTemplate $t): bool => ! $this->contentPolicy->isValid($t->subject, $t->body));

        if ($invalidTemplates->isNotEmpty()) {
            $items[] = [
                'severity' => 'danger',
                'title' => $invalidTemplates->count().' global template(s) fail content validation',
                'detail' => 'Affected keys: '.$invalidTemplates->take(3)->pluck('key')->implode(', ')
                    .'. Content must never contain executable constructs.',
            ];
        }

        foreach (AiPolicySetting::query()->get(['key', 'value_json']) as $setting) {
            $error = $this->aiRegistry->validate($setting->key, $setting->value_json);

            if ($error !== null) {
                $items[] = [
                    'severity' => 'danger',
                    'title' => 'AI policy "'.$setting->key.'" has an invalid value',
                    'detail' => $error,
                ];
            }
        }

        // An engaged platform AI kill switch is a real, high-signal
        // operational state an operator should never have to go looking
        // for.
        if (app(AiModeResolutionService::class)->platformKillSwitchEngaged()) {
            $items[] = [
                'severity' => 'danger',
                'title' => 'Platform AI kill switch is ENGAGED',
                'detail' => 'AI is disabled platform-wide for every firm, regardless of individual firm settings. Managed from AI Oversight.',
            ];
        }

        return $items;
    }

    /**
     * Capabilities this console deliberately does not offer, and why.
     * Surfaced in the UI so an operator learns a capability is absent
     * from the page itself rather than by finding an empty screen
     * (mission section 100).
     *
     * @return list<array{capability: string, status: string, reason: string}>
     */
    public function capabilityGaps(): array
    {
        return [
            [
                'capability' => 'Practice area alias resolution',
                'status' => 'Not implemented',
                'reason' => 'practice_areas.synonyms stores aliases, but no resolver consults it. Aliases are shown and checked for ambiguity; they do not resolve anything.',
            ],
            [
                'capability' => 'Practice area hierarchy',
                'status' => 'Not implemented',
                'reason' => 'practice_areas has no parent or category column.',
            ],
            [
                'capability' => 'Practice area merge',
                'status' => 'Analysis only',
                'reason' => 'No canonical merge service exists. Duplicate evidence and impact previews are available; executing a merge on real data requires separate owner approval.',
            ],
            [
                'capability' => 'Notification template versioning',
                'status' => 'Not implemented',
                'reason' => 'notification_templates has no version column, so published content cannot be reproduced historically.',
            ],
            [
                'capability' => 'Required notification template catalog',
                'status' => 'Not implemented',
                'reason' => 'No canonical required-event catalog exists to measure coverage against.',
            ],
            [
                'capability' => 'Notification variable registry',
                'status' => 'Not implemented',
                'reason' => 'No approved variable vocabulary exists, so variable names cannot be validated. Syntax and executable-content checks do apply.',
            ],
            [
                'capability' => 'Notification delivery from these templates',
                'status' => 'Template only',
                'reason' => 'A real SES transport exists but is used by hardcoded notifications that do not read this table. The templated dispatch path records events and performs no send.',
            ],
            [
                'capability' => 'Firm-level AI policy overrides',
                'status' => 'Not implemented',
                'reason' => 'ai_policy_settings is platform-level; it has no firm_id column.',
            ],
        ];
    }

    /**
     * Sanity guard used by the page: confirms the practice-area catalog
     * is readable at all, so an empty overview is never mistaken for a
     * healthy one.
     */
    public function practiceAreaCatalogSize(): int
    {
        return DB::table('practice_areas')->count();
    }
}
