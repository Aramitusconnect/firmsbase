<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\PlatformAdmin;
use App\Services\PlatformStaffAccessPolicyService;
use App\Services\PlatformSupportAccessDirectoryService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * PlatformSupportOverviewPage — Prompt 6. The entry point of the Support
 * section: what support access is currently outstanding, what is actively
 * running inside customer firms right now, and what needs an operator's
 * attention.
 *
 * Everything here is measured from real `support_access_requests` /
 * `support_access_sessions` rows through
 * PlatformSupportAccessDirectoryService::supportOverview(), which reuses
 * the SAME PlatformFirmIntegrationBoundedAccessService chokepoint the two
 * Support list views already use. An admin therefore never sees a count
 * that includes firms they are not permitted to read — the overview
 * cannot become an aggregate side channel around per-firm authorization.
 *
 * A measured zero is shown as zero. Nothing on this page is projected,
 * inferred from behaviour, or filled in with a placeholder when data is
 * absent — the attention signals are plain deterministic rules over
 * persisted state (a count of rows past a fixed window, a count of
 * sessions whose expiry is near), never scoring or anomaly detection.
 */
class PlatformSupportOverviewPage extends Page
{
    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

    protected static ?string $navigationLabel = 'Support Overview';

    protected static string|\UnitEnum|null $navigationGroup = 'Support';

    protected static ?int $navigationSort = 59;

    protected static ?string $title = 'Support Overview';

    protected static ?string $slug = 'support-overview';

    public static function canAccess(): bool
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return false;
        }

        // Same read gate as SupportCaseResource/SupportSessionResource —
        // this page is a summary OF those two lists and must not be
        // reachable by anyone who cannot read them.
        return app(PlatformStaffAccessPolicyService::class)->canAccessIntegrationOversight($admin)->allowed;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    public function getSubheading(): ?string
    {
        return 'Platform staff never hold standing access to a firm. Every entry below began as a request, '
            .'and standard access additionally required that firm\'s own approval.';
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
            $overview = app(PlatformSupportAccessDirectoryService::class)->supportOverview($admin);
        } catch (RuntimeException $exception) {
            return $schema->components([
                Text::make($exception->getMessage())->color('danger'),
            ]);
        }

        return $schema->components([
            Section::make('Access requests')
                ->description('Requests by platform staff to work inside a customer firm\'s data.')
                ->columns(4)
                ->schema([
                    Text::make('Pending firm approval: '.$overview['requests']['pending_firm_approval']),
                    Text::make('Approved: '.$overview['requests']['approved']),
                    Text::make('Denied: '.$overview['requests']['denied']),
                    Text::make('Expired: '.$overview['requests']['expired']),
                ]),

            Section::make('Support sessions')
                ->description('Active now counts only sessions the server would authorize at this moment — a session whose expiry has passed is excluded even if its stored status still reads Active.')
                ->columns(4)
                ->schema([
                    Text::make('Active now: '.$overview['sessions']['active_now']),
                    Text::make('Expiring soon: '.$overview['sessions']['expiring_soon']),
                    Text::make('Revoked: '.$overview['sessions']['revoked']),
                    Text::make('Ended: '.$overview['sessions']['ended']),
                ]),

            Section::make('Requires attention')
                ->description('Deterministic rules over current state — not predictions.')
                ->schema($this->attentionComponents($overview['attention'])),
        ]);
    }

    /**
     * @param  array<int, array{severity: string, title: string, detail: string, count: int}>  $attention
     * @return array<int, Text>
     */
    private function attentionComponents(array $attention): array
    {
        if ($attention === []) {
            return [
                Text::make('Nothing currently requires attention in support access.')->color('gray'),
            ];
        }

        return array_map(
            // The count and the explanation are both in the text itself, so
            // severity is never carried by colour alone.
            fn (array $item): Text => Text::make($item['title'].': '.$item['count'].' — '.$item['detail'])
                ->color($item['severity']),
            $attention,
        );
    }
}
