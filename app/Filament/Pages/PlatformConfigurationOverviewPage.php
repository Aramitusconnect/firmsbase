<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\PlatformAdmin;
use App\Services\Configuration\ConfigurationOverviewReadService;
use App\Services\PlatformStaffAccessPolicyService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * PlatformConfigurationOverviewPage — the entry point of the
 * Configuration group. No equivalent existed before this mission: the
 * group held four independent resources (Practice Areas, Entitlement
 * Overrides, AI Policy Settings, Notification Templates) with no place
 * to see configuration health as a whole, so this adds one rather than
 * duplicating anything.
 *
 * Scalar-property-only, mirroring PlatformIntegrationOverviewPage's
 * established shape: no Model-typed public property, and every read
 * happens fresh inside the schema closures on each render.
 *
 * THE DESIGN CONSTRAINT THAT SHAPES THIS PAGE. Two of the four
 * configuration domains are tenant-owned and FORCE-RLS protected, so a
 * platform session sees zero rows rather than an error. A dashboard
 * that printed those zeros would state, in the most authoritative place
 * in the console, that no firm has any entitlement override — while
 * being structurally incapable of knowing that. So unavailable metrics
 * render as an explanatory sentence and a pointer to the screen that
 * can answer them, never as the number 0 (mission sections 24 and 100).
 *
 * Everything actually shown is a bounded query against global tables or
 * the globally-readable subset of a tenant table. Nothing here performs
 * an O(number of firms) scan (mission section 91).
 */
class PlatformConfigurationOverviewPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $navigationLabel = 'Configuration Overview';

    protected static string|\UnitEnum|null $navigationGroup = 'Configuration';

    /**
     * Sorted above the four existing Configuration resources (70/71/72,
     * and Practice Areas which is unsorted) so it reads as the group's
     * entry point. No other navigation group is touched.
     */
    protected static ?int $navigationSort = 69;

    protected static ?string $title = 'Configuration Overview';

    protected static ?string $slug = 'configuration-overview';

    protected string $view = 'filament-panels::pages.page';

    public static function canAccess(): bool
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return false;
        }

        // Visible to anyone who can see ANY configuration surface. Each
        // underlying resource still applies its own gate, so this page
        // never widens access to data a viewer could not already reach.
        $policy = app(PlatformStaffAccessPolicyService::class);

        return $policy->canAccessEntitlementOverrides($admin)->allowed
            || $policy->canAccessAiPolicySettings($admin)->allowed
            || $policy->canAccessNotificationTemplates($admin)->allowed
            || $policy->canManagePracticeAreaCatalog($admin)->allowed;
    }

    public function content(Schema $schema): Schema
    {
        $reader = app(ConfigurationOverviewReadService::class);

        return $schema->components([
            $this->attentionSection($reader),
            $this->metricSection('Practice Areas', 'The global taxonomy every firm selects from.', $reader->practiceAreaMetrics()),
            $this->metricSection('Entitlements', 'Effective module access per firm.', $reader->entitlementMetrics()),
            $this->metricSection('AI Policy', 'Platform-wide AI guardrails.', $reader->aiPolicyMetrics()),
            $this->metricSection('Notification Templates', 'Global default template content.', $reader->notificationTemplateMetrics()),
            $this->capabilitySection($reader),
        ]);
    }

    private function attentionSection(ConfigurationOverviewReadService $reader): Section
    {
        $items = $reader->requiresAttention();

        if ($items === []) {
            return Section::make('Requires attention')
                ->description('Evaluated: practice area duplicates and aliases, matter type coverage, global template content validation, AI policy value types, and the platform AI kill switch.')
                ->schema([
                    Text::make('No configuration issues currently require attention.'),
                ]);
        }

        return Section::make('Requires attention')
            ->description(count($items).' item(s) need review.')
            ->schema(array_map(
                fn (array $item): Text => Text::make($this->severityPrefix($item['severity']).' '.$item['title'].' — '.$item['detail']),
                $items,
            ));
    }

    /**
     * @param  list<array<string, mixed>>  $metrics
     */
    private function metricSection(string $heading, string $description, array $metrics): Section
    {
        return Section::make($heading)
            ->description($description)
            ->collapsible()
            ->schema(array_map(
                function (array $metric): Text {
                    // An unavailable metric never renders a number. The
                    // label plus the reason is the whole point — see this
                    // class's own docblock.
                    $value = $metric['available']
                        ? (string) $metric['value']
                        : 'Not available';

                    $line = $metric['label'].': '.$value;

                    if (($metric['note'] ?? null) !== null) {
                        $line .= ' — '.$metric['note'];
                    }

                    return Text::make($line);
                },
                $metrics,
            ));
    }

    private function capabilitySection(ConfigurationOverviewReadService $reader): Section
    {
        return Section::make('Capabilities not available in this console')
            ->description('Stated explicitly so an absent capability is never mistaken for an empty screen.')
            ->collapsible()
            ->collapsed()
            ->schema(array_map(
                fn (array $gap): Text => Text::make($gap['capability'].' — '.$gap['status'].'. '.$gap['reason']),
                $reader->capabilityGaps(),
            ));
    }

    /**
     * Severity is conveyed by a text prefix, not colour alone (mission
     * section 86).
     */
    private function severityPrefix(string $severity): string
    {
        return match ($severity) {
            'danger' => '[Action required]',
            'warning' => '[Review]',
            default => '[Info]',
        };
    }
}
