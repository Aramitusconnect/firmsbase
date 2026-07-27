<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Firm;
use App\Models\NotificationTemplate;
use App\Models\PlatformAdmin;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * PlatformNotificationTemplateDirectoryService — Phase 4 (FirmsVault
 * Platform Admin Control Center, "Configuration" category). The read
 * path behind NotificationTemplateResource ("Notification Templates",
 * the honest relabeling of "Email Templates" — the backend is
 * channel-agnostic across Email/Sms/WhatsApp/Portal via the reused
 * ConsentChannel enum).
 *
 * Deliberately NARROWER than this phase's other cross-firm directory
 * services — per this phase's own architecture investigation §6's
 * "practical consequence for Phase 4" finding, `notification_templates`
 * carries a special, asymmetric dual-policy RLS design
 * (2026_08_25_930031_force_rls_on_notification_templates_table.php):
 * the SELECT policy is `firm_id IS NULL OR firm_id = current_firm` —
 * meaning EVERY session, including one with zero ambient tenant
 * context, can already read every global-default row directly, with NO
 * per-firm loop needed. This class therefore does not mirror
 * PlatformIntegrationCrossFirmDirectoryService/
 * PlatformSupportAccessDirectoryService's O(number of firms) per-firm
 * loop at all:
 *   - listGlobalDefaults() runs a single, direct, no-context query.
 *   - listForFirm() activates exactly ONE firm's context (never a
 *     loop) — under that firm's context, the same SELECT policy
 *     surfaces both that firm's own override rows AND every global
 *     default row in one query, which is exactly the comparison view a
 *     "does this firm override this template" workflow needs.
 * A full cross-firm "which firms have overridden this template" view
 * (which WOULD need the per-firm-loop pattern) is deliberately not
 * built this pass — see NotificationTemplateResource's own docblock
 * for the scoping rationale.
 */
class PlatformNotificationTemplateDirectoryService
{
    private const PER_QUERY_LIMIT = 200;

    private const TEMPLATE_COLUMNS = [
        'id', 'firm_id', 'key', 'channel', 'language', 'status', 'subject', 'body',
        'from_email', 'from_domain', 'spf_status', 'dkim_status', 'dmarc_status',
        'domain_verified_at', 'created_at', 'updated_at',
    ];

    public function __construct(
        private readonly PlatformStaffAccessPolicyService $accessPolicy,
        private readonly TenantContextService $tenantContext = new TenantContextService,
    ) {}

    public function assertCanAccess(PlatformAdmin $admin): void
    {
        $decision = $this->accessPolicy->canAccessNotificationTemplates($admin);

        if (! $decision->allowed) {
            throw new \RuntimeException($decision->reason ?? 'Not permitted to access notification templates.');
        }
    }

    /**
     * @param  array{key?: ?string, channel?: ?string, status?: ?string}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function list(PlatformAdmin $admin, ?Firm $firm, array $filters = []): Collection
    {
        $this->assertCanAccess($admin);

        $query = fn () => $this->baseQuery($filters)->get(self::TEMPLATE_COLUMNS);

        $templates = $firm === null
            ? $this->tenantContext->runWithoutFirmContext($query)
            : $this->tenantContext->runWithFirmContext($firm, $query);

        $firmNamesByid = $this->firmNamesFor($templates->pluck('firm_id')->filter()->unique());

        return $templates->map(fn (NotificationTemplate $template): array => $this->toRow($template, $firmNamesByid))->values();
    }

    public function find(PlatformAdmin $admin, ?Firm $firm, int $id): ?array
    {
        $this->assertCanAccess($admin);

        $query = fn () => NotificationTemplate::query()->where('id', $id)->first(self::TEMPLATE_COLUMNS);

        $template = $firm === null
            ? $this->tenantContext->runWithoutFirmContext($query)
            : $this->tenantContext->runWithFirmContext($firm, $query);

        if ($template === null) {
            return null;
        }

        $firmNamesById = $this->firmNamesFor(collect([$template->firm_id])->filter());

        return $this->toRow($template, $firmNamesById);
    }

    /**
     * Used by ArchiveNotificationTemplateAction to resolve the real
     * model instance.
     */
    public function findModel(PlatformAdmin $admin, ?Firm $firm, int $id): ?NotificationTemplate
    {
        $this->assertCanAccess($admin);

        $query = fn () => NotificationTemplate::query()->where('id', $id)->first();

        return $firm === null
            ? $this->tenantContext->runWithoutFirmContext($query)
            : $this->tenantContext->runWithFirmContext($firm, $query);
    }

    /**
     * @param  array{key?: ?string, channel?: ?string, status?: ?string}  $filters
     */
    private function baseQuery(array $filters): Builder
    {
        $key = $filters['key'] ?? null;
        $channel = $filters['channel'] ?? null;
        $status = $filters['status'] ?? null;

        return NotificationTemplate::query()
            ->when($key !== null && $key !== '', fn ($q) => $q->where('key', 'like', '%'.$key.'%'))
            ->when($channel !== null, fn ($q) => $q->where('channel', $channel))
            ->when($status !== null, fn ($q) => $q->where('status', $status))
            ->orderByDesc('updated_at')
            // Descending id tie-break — mirrors every other cross-firm
            // directory service in this phase/codebase (e.g.
            // PlatformIntegrationCrossFirmDirectoryService::
            // sortDeterministically()'s `$b['id'] <=> $a['id']`):
            // among equal-timestamp rows, the most-recently-created
            // (higher id) sorts first, consistent with the overall
            // newest-first ordering.
            ->orderByDesc('id')
            ->limit(self::PER_QUERY_LIMIT);
    }

    /**
     * @param  Collection<int, int>  $firmIds
     * @return array<int, string>
     */
    private function firmNamesFor(Collection $firmIds): array
    {
        if ($firmIds->isEmpty()) {
            return [];
        }

        return Firm::query()->whereIn('id', $firmIds)->pluck('name', 'id')->all();
    }

    /**
     * @param  array<int, string>  $firmNamesById
     * @return array<string, mixed>
     */
    private function toRow(NotificationTemplate $template, array $firmNamesById): array
    {
        return [
            'id' => $template->id,
            'firm_id' => $template->firm_id,
            'firm_name' => $template->firm_id === null ? null : ($firmNamesById[$template->firm_id] ?? null),
            'is_global_default' => $template->firm_id === null,
            'key' => $template->key,
            'channel' => $template->channel?->value,
            'language' => $template->language,
            'status' => $template->status?->value,
            'subject' => $template->subject,
            'body' => $template->body,
            'from_email' => $template->from_email,
            'from_domain' => $template->from_domain,
            'domain_verified_at' => $template->domain_verified_at,
            'created_at' => $template->created_at,
            'updated_at' => $template->updated_at,
        ];
    }
}
