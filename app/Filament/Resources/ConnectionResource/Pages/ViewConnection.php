<?php

declare(strict_types=1);

namespace App\Filament\Resources\ConnectionResource\Pages;

use App\Filament\Actions\Platform\DisconnectConnectionAction;
use App\Filament\Pages\PlatformFirmIntegrationDetailPage;
use App\Filament\Resources\ConnectionResource;
use App\Models\Firm;
use App\Models\PlatformAdmin;
use App\Services\PlatformConnectionDirectoryService;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\UnorderedList;
use Filament\Schemas\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * ViewConnection — a custom Resource page, NOT the standard Filament
 * ViewRecord (`{record}` route-model-binding). See ConnectionResource's
 * own docblock for why: a FirmIntegration row cannot be resolved by its
 * own uuid alone under firm_integrations' FORCE RLS without the correct
 * firm's context already active, so the route carries both `firmUuid`
 * and `connectionUuid` — mirroring
 * App\Filament\Resources\FirmUserResource\Pages\ViewFirmUser's
 * established `{firmUuid}/{firmUserUuid}` composite-route shape exactly.
 *
 * Scalar-property-only, TOCTOU-consistent with that same precedent: the
 * only public properties are the two route-parameter strings; the
 * actual connection row is re-resolved fresh via
 * PlatformConnectionDirectoryService::findByUuid() inside content() (and
 * again, independently, inside the Disconnect header action's bound
 * ->record() closure below), never cached on $this between renders
 * beyond what mount() needs for its own one-time 403/404 check.
 *
 * Cross-linking coordination point (see this pass's own final report):
 * a parallel agent is building Sync Failures/Webhook Events/Dead-Letter
 * Queue/Conflicts resources concurrently, in separate files this pass
 * does not touch. Those resources do not exist yet at the time this
 * file was written/tested, so every link to one of them below is
 * guarded via crossLinkIfAvailable() (class_exists() + a try/catch
 * around ::getUrl()) — a missing target renders a plain "not yet
 * available" line instead of erroring. The exact FQCN/route/filter-key
 * guesses below are placeholders per this pass's own dispatch
 * instructions and MUST be verified/corrected once the parallel pass
 * lands (see this pass's final report for the full candidate list).
 */
class ViewConnection extends Page
{
    protected static string $resource = ConnectionResource::class;

    protected string $view = 'filament-panels::pages.page';

    protected static ?string $title = 'Connection';

    public string $firmUuid = '';

    public string $connectionUuid = '';

    public function mount(string $firmUuid, string $connectionUuid): void
    {
        $this->firmUuid = $firmUuid;
        $this->connectionUuid = $connectionUuid;

        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            abort(403);
        }

        $firm = Firm::findByUuid($this->firmUuid);

        try {
            $row = app(PlatformConnectionDirectoryService::class)->findByUuid($admin, $firm, $this->connectionUuid);
        } catch (RuntimeException $e) {
            throw new HttpException(403, $e->getMessage());
        }

        if ($row === null) {
            abort(404);
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            DisconnectConnectionAction::make()->record(fn (): ?array => $this->loadRow()),
        ];
    }

    public function content(Schema $schema): Schema
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return $schema->components([
                Text::make('You are not signed in as a platform admin.')->color('danger'),
            ]);
        }

        try {
            $row = $this->loadRow();
        } catch (RuntimeException $e) {
            return $schema->components([
                Text::make($e->getMessage())->color('danger'),
            ]);
        }

        if ($row === null) {
            return $schema->components([
                Text::make('This connection could not be found.')->color('danger'),
            ]);
        }

        return $schema->components([
            $this->connectionSection($row),
            $this->healthSection($row),
            $this->credentialSection($row),
            $this->relatedSection($row),
        ]);
    }

    /**
     * Re-resolves fresh on every call — never cached on $this. Used by
     * both content() and the Disconnect header action's ->record()
     * binding above, each independently.
     *
     * @return array<string, mixed>|null
     */
    private function loadRow(): ?array
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return null;
        }

        $firm = Firm::findByUuid($this->firmUuid);

        return app(PlatformConnectionDirectoryService::class)->findByUuid($admin, $firm, $this->connectionUuid);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function connectionSection(array $row): Section
    {
        return Section::make('Connection')
            ->columns(2)
            ->schema([
                Text::make('Firm: '.($row['firm_name'] ?? '—')),
                Text::make('Provider: '.($row['provider_display_name'] ?? '—')),
                Text::make('Display label: '.($row['display_label'] ?? '—')),
                Text::make('Status: '.Str::headline((string) ($row['status'] ?? '—')))->badge(),
                Text::make('Integration access (entitlement): '.(($row['entitlement_enabled'] ?? false) ? 'Enabled' : 'Disabled')),
                Text::make('External account (masked): '.($row['masked_external_account_id'] ?? '—')),
                Text::make('Connected at: '.$this->formatTimestamp($row['connected_at'] ?? null)),
                Text::make('Disconnected at: '.$this->formatTimestamp($row['disconnected_at'] ?? null)),
                Text::make('Created: '.$this->formatTimestamp($row['created_at'] ?? null)),
                Text::make('Updated: '.$this->formatTimestamp($row['updated_at'] ?? null)),
            ]);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function healthSection(array $row): Section
    {
        return Section::make('Health, Retry & Rate Limit')
            ->columns(2)
            ->schema([
                Text::make('Health: '.($row['health_summary_state'] !== null ? Str::headline((string) $row['health_summary_state']) : '—')),
                Text::make('Diagnostic: '.($row['sanitized_diagnostic_summary'] ?? '—')),
                Text::make('Last successful sync: '.$this->formatTimestamp($row['last_successful_sync_at'] ?? null)),
                Text::make('Last failure: '.$this->formatTimestamp($row['last_failure_at'] ?? null)),
                Text::make('Last failure category: '.($row['last_failure_category'] ?? '—')),
                Text::make('Consecutive failures: '.($row['consecutive_failures'] ?? 0)),
                Text::make('Next retry at: '.$this->formatTimestamp($row['next_retry_at'] ?? null)),
                Text::make('Rate limit resets at: '.$this->formatTimestamp($row['rate_limited_reset_at'] ?? null, 'Not rate-limited')),
            ])
            ->collapsible();
    }

    /**
     * Never decrypts anything — every value here comes from
     * IntegrationCredentialService::getMaskedMetadata() (no decrypt call
     * at all), via PlatformConnectionDirectoryService.
     *
     * @param  array<string, mixed>  $row
     */
    private function credentialSection(array $row): Section
    {
        $credentials = $row['masked_credentials'] ?? [];

        return Section::make('Credential Health')
            ->description('Masked metadata only — no token/secret/API key value is ever shown here.')
            ->schema([
                UnorderedList::make(function () use ($credentials): array {
                    if ($credentials === []) {
                        return ['No active credential for this connection.'];
                    }

                    return collect($credentials)
                        ->map(fn (array $credential): string => sprintf(
                            '%s — %s — expires %s',
                            $this->scalarize($credential['credential_type'] ?? null),
                            $this->scalarize($credential['status'] ?? null),
                            $this->formatTimestamp($credential['expires_at'] ?? null, 'never'),
                        ))
                        ->all();
                }),
            ])
            ->collapsible();
    }

    /**
     * The required cross-links: to the per-firm oversight drill-down
     * (existing, always available) and to the parallel-agent-owned
     * resources (guarded — see this class's own docblock).
     *
     * @param  array<string, mixed>  $row
     */
    private function relatedSection(array $row): Section
    {
        $connectionId = (int) ($row['id'] ?? 0);

        $lines = [
            sprintf(
                'Firm oversight drill-down: %s',
                PlatformFirmIntegrationDetailPage::getUrl(['firmUuid' => $this->firmUuid, 'connectionUuid' => $this->connectionUuid]),
            ),
        ];

        $targets = [
            'Sync Failures' => ['App\Filament\Resources\SyncFailureResource', 'App\Filament\Resources\SyncFailuresResource'],
            'Webhook Events' => ['App\Filament\Resources\WebhookEventResource', 'App\Filament\Resources\WebhookEventsResource'],
            'Dead-Letter Queue' => ['App\Filament\Resources\DeadLetterQueueResource', 'App\Filament\Resources\DeadLetterEventResource', 'App\Filament\Resources\DeadLetteredEventResource'],
            'Conflicts' => ['App\Filament\Resources\ConflictResource', 'App\Filament\Resources\IntegrationConflictResource'],
        ];

        foreach ($targets as $label => $candidates) {
            $url = $this->crossLinkIfAvailable($candidates, $connectionId);

            $lines[] = $url !== null
                ? "{$label}: {$url}"
                : "{$label}: not yet available (module not merged into this branch yet).";
        }

        // "Integration Usage" — discovered (once the parallel pass
        // landed in this shared worktree) to be
        // App\Filament\Pages\PlatformIntegrationUsagePage, a firm/
        // platform-wide aggregate Page with NO per-connection filtering
        // support at all (by design — see that class's own "HONESTY-
        // OVER-COMPLETENESS DISCLOSURE" docblock) and a different
        // ::getUrl(array $parameters) signature than a Resource's
        // ::getUrl(string $name, array $parameters). Linked plainly
        // (no filter params) rather than forced into the Resource-shaped
        // guard above, which would always fail for it.
        $lines[] = $this->crossLinkToUsagePage();

        return Section::make('Related')
            ->description('Cross-links to other Integration Operations Center modules for this same connection.')
            ->schema([
                UnorderedList::make($lines),
            ])
            ->collapsible();
    }

    private function crossLinkToUsagePage(): string
    {
        $class = 'App\Filament\Pages\PlatformIntegrationUsagePage';

        if (! class_exists($class) || ! method_exists($class, 'getUrl')) {
            return 'Integration Usage: not yet available (module not merged into this branch yet).';
        }

        try {
            return 'Integration Usage: '.$class::getUrl();
        } catch (Throwable $e) {
            Log::info('ViewConnection: PlatformIntegrationUsagePage exists but getUrl() failed — leaving it unlinked rather than crashing.', [
                'exception' => $e->getMessage(),
            ]);

            return 'Integration Usage: not yet available (module not merged into this branch yet).';
        }
    }

    /**
     * @param  array<int, string>  $candidateClasses
     */
    private function crossLinkIfAvailable(array $candidateClasses, int $connectionId): ?string
    {
        foreach ($candidateClasses as $class) {
            if (! class_exists($class) || ! method_exists($class, 'getUrl')) {
                continue;
            }

            try {
                return $class::getUrl('index', ['tableFilters' => ['connection' => ['value' => $connectionId]]]);
            } catch (Throwable $e) {
                Log::info('ViewConnection: a candidate cross-link target class exists but getUrl() failed — leaving it unlinked rather than crashing.', [
                    'class' => $class,
                    'exception' => $e->getMessage(),
                ]);

                continue;
            }
        }

        return null;
    }

    /**
     * IntegrationCredentialService::getMaskedMetadata() returns
     * credential_type/status as their real cast enum objects (BackedEnum)
     * — never assumed to already be strings/scalars.
     */
    private function scalarize(mixed $value): string
    {
        if ($value === null) {
            return '—';
        }

        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        return (string) $value;
    }

    private function formatTimestamp(mixed $value, string $placeholder = '—'): string
    {
        if ($value === null || $value === '') {
            return $placeholder;
        }

        return Carbon::parse($value)->toDayDateTimeString();
    }
}
