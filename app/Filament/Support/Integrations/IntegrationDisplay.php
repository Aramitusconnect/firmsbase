<?php

declare(strict_types=1);

namespace App\Filament\Support\Integrations;

use App\Integrations\Core\ProviderRegistry;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\IntegrationProvider;
use Illuminate\Support\Str;

/**
 * IntegrationDisplay — Integration-owned presentation helper for the
 * SuperAdmin Integrations navigation group. Deliberately NOT a shared
 * Admin-wide badge/status helper: every method here encodes an
 * Integration-domain semantic (provider identity, "measured zero vs
 * never measured", provider-health vocabulary) that only the
 * Integration surfaces should share, so no other parallel Admin
 * mission's files need to change to adopt or avoid it.
 *
 * WHY THIS EXISTS — two concrete, previously-observed defects:
 *
 *  1. RAW PROVIDER SLUGS. PlatformProviderHealthPage rendered
 *     `provider_code` directly ("googleworkspace"), and
 *     WebhookEventResource's provider filter ran the raw key through
 *     Str::headline(), producing "Googleworkspace"/"Microsoft365".
 *     labelForProviderCode() resolves the ONE canonical label instead,
 *     preferring the seeded `integration_providers.display_name`
 *     catalog row and falling back to the code-defined ProviderKey
 *     registry — never a hand-maintained third copy of the mapping.
 *
 *  2. AMBIGUOUS EM-DASHES. Every Integration table used a bare "—"
 *     placeholder for at least four structurally different facts:
 *     "this provider does not have this capability at all", "this has
 *     never been evaluated", "this was evaluated and produced no
 *     value", and "this is genuinely zero". An operator cannot tell a
 *     healthy silence from an unmonitored one. The constants below name
 *     each case explicitly so a column author must pick the one that is
 *     actually true.
 *
 * NOTHING here fabricates a metric. Every method takes an
 * already-persisted value and only decides how to NAME it; there is no
 * default, no synthesized timestamp, and no network call anywhere in
 * this class.
 */
final class IntegrationDisplay
{
    /**
     * The capability/dimension genuinely does not apply to this
     * provider (e.g. webhook health for a provider that implements no
     * webhook contract at all). Distinct from NOT_CHECKED: there is
     * nothing here to check, now or ever.
     */
    public const NOT_APPLICABLE = 'Not applicable';

    /**
     * The dimension applies but no evaluation has ever run. Distinct
     * from a measured zero — this is the case §30/§37 of the mission
     * brief specifically forbids rendering as "0".
     */
    public const NEVER_CHECKED = 'Never checked';

    /**
     * An evaluation ran but could not determine a state (provider
     * returned nothing usable, telemetry absent for the window).
     * Distinct from NEVER_CHECKED: something ran, the answer is
     * unknown.
     */
    public const UNKNOWN = 'Unknown';

    /**
     * The underlying telemetry is not collected at all in this
     * deployment — an honest "we do not measure this", never a zero.
     */
    public const NOT_MEASURED = 'Not measured';

    /** The dimension exists but has not been configured for use. */
    public const NOT_CONFIGURED = 'Not configured';

    /**
     * A rollup whose inputs have never been evaluated. Used where a
     * DERIVED state (e.g. an overall provider health badge) has nothing
     * to derive from — never rendered as "Healthy" (§33/§37).
     */
    public const NOT_CHECKED = 'Not checked';

    /** No observation has been recorded yet in the selected window. */
    public const NO_DATA_YET = 'No data yet';

    /** Provider-supplied rate-limit telemetry was absent on this record. */
    public const NOT_REPORTED = 'Not reported';

    /**
     * Operator-facing label for a provider identified by its stored
     * string code (`firm_integrations.provider_code`,
     * `integration_platform_provider_health_summaries.provider_code`,
     * `integration_inbound_webhook_events.provider_key`, ...).
     *
     * Resolution order, most authoritative first:
     *   1. the seeded `integration_providers` catalog row's
     *      `display_name` (the same source ConnectionResource's own
     *      provider filter already uses, so a label never diverges
     *      between two Integration screens);
     *   2. the code-defined ProviderKey registry's own known labels,
     *      for a key whose catalog row has not been seeded in this
     *      environment;
     *   3. the raw code, headline-cased, as a last resort — an unknown
     *      code is shown verbatim rather than silently relabelled as
     *      something it is not.
     *
     * `ProviderKey::Test` deliberately renders as "Internal / Test" so
     * an internal fixture provider is never mistaken for a
     * customer-supported integration on a cross-firm operator screen.
     */
    public static function labelForProviderCode(?string $code): string
    {
        $code = is_string($code) ? trim($code) : '';

        if ($code === '') {
            return self::UNKNOWN;
        }

        $catalogLabel = self::catalogLabels()[$code] ?? null;

        if ($catalogLabel !== null && trim($catalogLabel) !== '') {
            return $catalogLabel;
        }

        $known = self::knownProviderKeyLabels()[$code] ?? null;

        if ($known !== null) {
            return $known;
        }

        return Str::headline($code);
    }

    /**
     * Whether a provider code refers to the internal fixture provider —
     * used by screens that want to visually de-emphasise it rather than
     * present it alongside real, customer-facing providers.
     */
    public static function isInternalProviderCode(?string $code): bool
    {
        return is_string($code) && trim($code) === ProviderKey::Test->value;
    }

    /**
     * Canonical [code => label] options for a provider SelectFilter,
     * covering every provider key this codebase defines (not merely the
     * ones currently resolvable from config, so a filter never silently
     * drops a provider whose adapter is environment-gated off and whose
     * historical rows still exist).
     *
     * @return array<string, string>
     */
    public static function providerFilterOptions(): array
    {
        $options = [];

        foreach (ProviderKey::cases() as $case) {
            $options[$case->value] = self::labelForProviderCode($case->value);
        }

        asort($options);

        return $options;
    }

    /**
     * Provider options restricted to providers whose adapter is
     * actually registered/resolvable right now, excluding the internal
     * fixture provider — the correct option list for an action that
     * will take a real operational effect against a live provider.
     *
     * @return array<string, string>
     */
    public static function liveProviderOptions(): array
    {
        $registry = app(ProviderRegistry::class);
        $options = [];

        foreach (ProviderKey::cases() as $case) {
            if ($case === ProviderKey::Test) {
                continue;
            }

            if (! $registry->has($case)) {
                continue;
            }

            $options[$case->value] = self::labelForProviderCode($case->value);
        }

        asort($options);

        return $options;
    }

    /**
     * Renders a count that is genuinely measured. A zero here means
     * "measured, and the answer is zero" — the caller must only use
     * this for a column whose source row is known to have been
     * computed. Use nullableCount() when the value's absence is itself
     * meaningful.
     */
    public static function measuredCount(?int $value, string $zeroLabel = '0'): string
    {
        if ($value === null) {
            return self::NOT_MEASURED;
        }

        return $value === 0 ? $zeroLabel : (string) $value;
    }

    /**
     * Renders a value that may legitimately be absent, naming WHY it is
     * absent rather than collapsing every absence into "—".
     */
    public static function orAbsent(mixed $value, string $absentLabel = self::UNKNOWN): string
    {
        if ($value === null) {
            return $absentLabel;
        }

        $string = is_scalar($value) ? trim((string) $value) : '';

        return $string === '' ? $absentLabel : $string;
    }

    /**
     * Operator-facing label for a persisted health-signal string, with
     * an explicit name for the null case supplied by the caller (which
     * knows whether a null on ITS row means "never evaluated" or "not
     * applicable to this provider"). Never invents a health state.
     */
    public static function healthSignal(?string $state, string $absentLabel = self::NEVER_CHECKED): string
    {
        if ($state === null || trim($state) === '') {
            return $absentLabel;
        }

        return Str::headline(trim($state));
    }

    /**
     * Filament badge colour for a health-shaped state string, shared by
     * every Integration surface so "degraded" is the same colour on
     * Provider Health, Connections, and the Overview. An unrecognised
     * or absent state is always 'gray' — never optimistically 'success'.
     */
    public static function healthColor(?string $state): string
    {
        return match (is_string($state) ? trim($state) : null) {
            'healthy', 'ok', 'connected', 'active', 'processed', 'resolved' => 'success',
            'degraded', 'action_required', 'near_limit', 'expiring_soon', 'awaiting_review', 'retrying' => 'warning',
            'unavailable', 'outage', 'failed', 'error', 'expired', 'revoked', 'exceeded', 'exhausted' => 'danger',
            default => 'gray',
        };
    }

    /**
     * The seeded catalog's [code => display_name] map, resolved once
     * per request. Falls back to an empty map (never an exception) when
     * the catalog table is unreadable in the current context, so a
     * presentation helper can never take a monitoring page down —
     * mission §28's provider-failure-isolation requirement applied to
     * this class's own dependency.
     *
     * `integration_providers` carries no RLS and is global, seeded-only
     * reference data (see that model's own docblock), so reading it
     * from the zero-tenant-context Admin panel is safe.
     *
     * Memoized on the CONTAINER, not in a `static` local: a static
     * would survive across tests in the same PHP process (and across
     * requests under a long-running worker), so a freshly-seeded
     * catalog row would keep rendering a stale label. A container
     * binding is torn down with the application instance, which is
     * exactly the lifetime this cache should have.
     *
     * @return array<string, string>
     */
    private static function catalogLabels(): array
    {
        $memoKey = self::class.'@catalogLabels';

        if (app()->bound($memoKey)) {
            /** @var array<string, string> $memoized */
            $memoized = app($memoKey);

            return $memoized;
        }

        try {
            $labels = IntegrationProvider::query()
                ->pluck('display_name', 'code')
                ->filter(fn (mixed $name): bool => is_string($name) && trim($name) !== '')
                ->all();
        } catch (\Throwable) {
            $labels = [];
        }

        /** @var array<string, string> $labels */
        app()->instance($memoKey, $labels);

        return $labels;
    }

    /**
     * Code-defined fallback labels for every ProviderKey case. Kept in
     * sync with the enum by construction: a new case with no entry here
     * falls through to Str::headline() rather than silently taking
     * another provider's label.
     *
     * @return array<string, string>
     */
    private static function knownProviderKeyLabels(): array
    {
        return [
            ProviderKey::Microsoft365->value => 'Microsoft 365',
            ProviderKey::GoogleWorkspace->value => 'Google Workspace',
            ProviderKey::Plaid->value => 'Plaid',
            ProviderKey::Test->value => 'Internal / Test',
        ];
    }
}
