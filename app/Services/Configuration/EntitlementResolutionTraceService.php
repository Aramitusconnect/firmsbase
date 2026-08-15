<?php

declare(strict_types=1);

namespace App\Services\Configuration;

use App\Enums\EntitlementSource;
use App\Models\Firm;
use App\Models\FirmEntitlement;
use App\Models\ModuleCatalog;
use App\Services\EntitlementService;
use App\Services\TenantContextService;
use Illuminate\Support\Collection;

/**
 * EntitlementResolutionTraceService — read-only. Explains WHY a firm
 * does or does not have a module, in the shape mission section 42
 * requires: one line per entitlement source, what each says, which one
 * won, and why the losers lost.
 *
 * IT DOES NOT RESOLVE ANYTHING ITSELF. Mission sections 40/99 forbid a
 * second precedence implementation, and this codebase has exactly one
 * canonical resolver — EntitlementService::resolve(), which ranks by
 * EntitlementSource::precedence() and filters by
 * FirmEntitlement::isWithinActiveWindow(). The effective state and the
 * winning source reported here are taken VERBATIM from that resolver's
 * EntitlementResolution. The raw rows this class reads are used only to
 * describe each source's CONFIGURED state; nothing here re-sorts,
 * re-ranks, or re-decides. If the canonical precedence rules ever
 * change, this trace changes with them automatically, because it never
 * encoded them in the first place.
 *
 * TENANT CONTEXT SEQUENCING — load-bearing. `firm_entitlements` is
 * FORCE-RLS protected, so reading the raw rows requires an active firm
 * context. But EntitlementService::resolve() self-wraps its whole body
 * in runWithFirmContext() and its docblock is explicit that it must
 * never be called from inside an already-active outer context (the
 * "decoy wrap" bug: the inner finally would clear the outer caller's
 * context on return). So this class reads the raw rows inside its own
 * wrap, lets that wrap CLOSE, and only then calls resolve(). The two
 * are deliberately never nested.
 */
class EntitlementResolutionTraceService
{
    /**
     * Every source in canonical precedence order, highest first. Order
     * is derived from EntitlementSource::precedence() rather than
     * hardcoded, so a future change to the enum reorders this display
     * automatically instead of silently disagreeing with the resolver.
     *
     * @return list<EntitlementSource>
     */
    public static function sourcesByPrecedenceDesc(): array
    {
        $sources = EntitlementSource::cases();

        usort($sources, fn (EntitlementSource $a, EntitlementSource $b): int => $b->precedence() <=> $a->precedence());

        return $sources;
    }

    public function __construct(
        private readonly EntitlementService $entitlements = new EntitlementService,
        private readonly TenantContextService $tenantContext = new TenantContextService,
    ) {}

    /**
     * The full resolution trace for one (firm, module) pair.
     *
     * @return array{
     *     firm_uuid: string,
     *     firm_name: string,
     *     module_code: string,
     *     module_name: string,
     *     effective_enabled: bool,
     *     effective_label: string,
     *     winning_source: ?EntitlementSource,
     *     winning_source_label: string,
     *     rows: list<array<string, mixed>>,
     *     has_any_record: bool,
     * }
     */
    public function trace(Firm $firm, string $moduleCode): array
    {
        // Step 1 — raw configured rows, inside our own context wrap.
        $rows = $this->configuredRows($firm, $moduleCode);

        // Step 2 — the wrap above has closed. Only now may the canonical
        // resolver run (it establishes its own context). See docblock.
        $resolution = $this->entitlements->resolve($firm->id, $moduleCode);

        $winningEntitlementId = $resolution->entitlement?->id;

        $traceRows = [];

        foreach (self::sourcesByPrecedenceDesc() as $source) {
            $record = $rows->get($source->value);

            $traceRows[] = [
                'source' => $source,
                'source_label' => $this->sourceLabel($source),
                'precedence' => $source->precedence(),
                'present' => $record !== null,
                'configured_state' => $this->configuredStateLabel($record),
                'window_state' => $this->windowStateLabel($record),
                'starts_at' => $record?->starts_at,
                'ends_at' => $record?->ends_at,
                'is_winner' => $record !== null && $record->id === $winningEntitlementId,
                'why_not_winner' => $this->whyNotWinner($record, $winningEntitlementId, $resolution->source),
                'entitlement_id' => $record?->id,
                'is_override' => in_array($source, [EntitlementSource::FirmOverride, EntitlementSource::AdminOverride], true),
            ];
        }

        return [
            'firm_uuid' => $firm->uuid,
            'firm_name' => $firm->name,
            'module_code' => $moduleCode,
            'module_name' => $this->moduleName($moduleCode),
            'effective_enabled' => $resolution->enabled,
            'effective_label' => $resolution->source === null
                ? 'Not entitled'
                : ($resolution->enabled ? 'Enabled' : 'Disabled'),
            'winning_source' => $resolution->source,
            'winning_source_label' => $resolution->source === null
                ? 'No source — no entitlement record is currently in effect'
                : $this->sourceLabel($resolution->source),
            'rows' => $traceRows,
            'has_any_record' => $rows->isNotEmpty(),
        ];
    }

    /**
     * Friendly module name from the canonical ModuleCatalog. Falls back
     * to a humanized code when the catalog has no row — an honest
     * best-effort label, never a second hardcoded display catalog
     * (mission section 43).
     */
    public function moduleName(string $moduleCode): string
    {
        $name = ModuleCatalog::query()->where('module_code', $moduleCode)->value('module_name');

        return is_string($name) && $name !== ''
            ? $name
            : str($moduleCode)->headline()->toString();
    }

    /**
     * @return array<string, string> module_code => friendly name
     */
    public function moduleNameMap(): array
    {
        return ModuleCatalog::query()
            ->orderBy('module_name')
            ->pluck('module_name', 'module_code')
            ->all();
    }

    public function sourceLabel(EntitlementSource $source): string
    {
        return match ($source) {
            EntitlementSource::AdminOverride => 'Platform admin override',
            EntitlementSource::FirmOverride => 'Firm override',
            EntitlementSource::OrgInherited => 'Organization inherited',
            EntitlementSource::Plan => 'Plan',
        };
    }

    /**
     * @return Collection<string, FirmEntitlement> keyed by source value
     */
    private function configuredRows(Firm $firm, string $moduleCode): Collection
    {
        return $this->tenantContext->runWithFirmContext(
            $firm,
            fn (): Collection => FirmEntitlement::query()
                ->where('firm_id', $firm->id)
                ->where('module_code', $moduleCode)
                ->get()
                ->keyBy(fn (FirmEntitlement $entitlement): string => $entitlement->source?->value ?? 'unknown'),
        );
    }

    private function configuredStateLabel(?FirmEntitlement $record): string
    {
        if ($record === null) {
            // Mission section 24: distinguish "no record" from a
            // configured "disabled" — they are different facts.
            return 'Not configured';
        }

        return $record->enabled ? 'Enabled' : 'Disabled';
    }

    private function windowStateLabel(?FirmEntitlement $record): string
    {
        if ($record === null) {
            return 'Not applicable';
        }

        $now = now();

        if ($record->starts_at && $record->starts_at->isAfter($now)) {
            return 'Scheduled — not yet in effect';
        }

        if ($record->ends_at && $record->ends_at->isBefore($now)) {
            return 'Expired';
        }

        return $record->ends_at === null ? 'In effect — no end date' : 'In effect';
    }

    /**
     * A plain-language reason each non-winning source did not win.
     * Derived from the SAME two facts the canonical resolver uses — the
     * active window and the precedence ranking — but only ever to
     * explain an outcome already decided by the resolver.
     */
    private function whyNotWinner(?FirmEntitlement $record, ?int $winningId, ?EntitlementSource $winningSource): ?string
    {
        if ($record === null) {
            return 'No entitlement record exists for this source.';
        }

        if ($record->id === $winningId) {
            return null;
        }

        if (! $record->isWithinActiveWindow()) {
            return $record->starts_at && $record->starts_at->isAfter(now())
                ? 'Outside its active window — starts in the future.'
                : 'Outside its active window — already expired.';
        }

        if ($winningSource !== null) {
            return sprintf(
                'Outranked by %s (precedence %d vs %d).',
                $this->sourceLabel($winningSource),
                $winningSource->precedence(),
                $record->source->precedence(),
            );
        }

        return 'Not currently in effect.';
    }
}
