<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Models\PlatformAdmin;
use App\Services\OperationsOverviewService;
use App\Services\PlatformStaffAccessPolicyService;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * PlatformOperationsOverviewPage — the entry point to the Operations
 * console. Operations Control Plane addition; no equivalent existed.
 *
 * It answers, from real evidence only: what is broken, what is
 * degraded, what is unknown, what is stale, what is not monitored at
 * all, what changed recently, and what needs a human now.
 *
 * The organising principle is that this page must be MORE
 * conservative than the pages it summarises, not less. A summary is
 * where nuance normally gets lost — where "not monitored" quietly
 * becomes a green tile and "no data" becomes a zero. So every section
 * here names its own evidence source, unavailable signals are
 * labelled Not Monitored or Not Available rather than defaulted, and
 * known coverage gaps are given their own section instead of being
 * folded into the alert queue where they would be permanent noise.
 *
 * All reads are bounded and indexed; no external probe is performed
 * during render.
 */
class PlatformOperationsOverviewPage extends Page
{
    protected string $view = 'filament-panels::pages.page';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static ?string $navigationLabel = 'Operations Overview';

    protected static string|\UnitEnum|null $navigationGroup = 'Operations';

    // First in the Operations group: this is the page an operator
    // should land on before drilling into any single domain.
    protected static ?int $navigationSort = 80;

    protected static ?string $title = 'Operations Overview';

    public static function canAccess(): bool
    {
        $admin = Auth::guard('platform_admin')->user();

        if (! $admin instanceof PlatformAdmin) {
            return false;
        }

        return app(PlatformStaffAccessPolicyService::class)->canAccessOperations($admin)->allowed;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::canAccess();
    }

    public function content(Schema $schema): Schema
    {
        $overview = app(OperationsOverviewService::class);

        return $schema->components([
            $this->requiresAttentionSection($overview),
            $this->platformHealthSection($overview),
            $this->incidentsSection($overview),
            $this->queuesSection($overview),
            $this->schedulerSection($overview),
            $this->dataProtectionSection($overview),
            $this->releaseSection($overview),
            $this->fleetSection($overview),
            $this->statusCommunicationsSection($overview),
            $this->recentChangesSection($overview),
            $this->coverageGapsSection($overview),
        ]);
    }

    /**
     * What needs a human right now. Deliberately first on the page,
     * and deliberately empty most of the time — an attention queue
     * that always has something in it is not an attention queue.
     */
    private function requiresAttentionSection(OperationsOverviewService $overview): Section
    {
        $items = $overview->requiresAttention();

        if ($items === []) {
            return Section::make('Requires Attention — None')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->schema([
                    Text::make('No monitored operational issue currently requires attention.')->color('success'),
                    Text::make(
                        'This covers only the surfaces this platform can actually observe. Several surfaces are not '.
                        'monitored at all — see Coverage Gaps at the bottom of this page. "Nothing requires '.
                        'attention" is not the same as "nothing is wrong."'
                    )->color('gray'),
                ]);
        }

        return Section::make('Requires Attention ('.count($items).')')
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->schema(array_map(
                fn (array $item): Text => Text::make(sprintf('[%s] %s — %s', $item['area'], $item['condition'], $item['detail']))
                    ->color($item['severity'] === 'critical' ? 'danger' : 'warning'),
                $items,
            ));
    }

    private function platformHealthSection(OperationsOverviewService $overview): Section
    {
        $health = $overview->platformHealth();

        return Section::make('Platform Health')
            ->icon(Heroicon::OutlinedHeart)
            ->schema([
                Text::make('Overall: '.$health['overall']->label())->color($health['overall']->color()),
                Text::make(sprintf(
                    'Healthy %d · Degraded %d · Critical %d · Unknown %d · Not Monitored %d (of %d checks)',
                    $health['healthy'],
                    $health['degraded'],
                    $health['critical'],
                    $health['unknown'],
                    $health['not_monitored'],
                    $health['total'],
                )),
                Text::make(sprintf(
                    'Stale observations: %d · Never observed: %d',
                    $health['stale'],
                    $health['never_observed'],
                )),
                Text::make('Source: '.$health['source'])->color('gray'),
            ]);
    }

    private function incidentsSection(OperationsOverviewService $overview): Section
    {
        $incidents = $overview->incidents();

        return Section::make('Incidents')
            ->icon(Heroicon::OutlinedBellAlert)
            ->schema([
                Text::make(sprintf(
                    'Active: %d (critical: %d) · Investigating %d · Identified %d · Monitoring %d',
                    $incidents['active'],
                    $incidents['critical_active'],
                    $incidents['by_status']['investigating'] ?? 0,
                    $incidents['by_status']['identified'] ?? 0,
                    $incidents['by_status']['monitoring'] ?? 0,
                ))->color($incidents['critical_active'] > 0 ? 'danger' : null),
                Text::make(sprintf(
                    'With customer impact: %d · Flagged for customer notification: %d',
                    $incidents['unresolved_with_customer_impact'],
                    $incidents['awaiting_customer_notification'],
                )),
                Text::make('Incident ownership is Not Recorded — this platform has no owner or commander field.')->color('warning'),
                Text::make('Source: '.$incidents['source'])->color('gray'),
            ]);
    }

    private function queuesSection(OperationsOverviewService $overview): Section
    {
        $queues = $overview->queues();

        return Section::make('Queues & Workers')
            ->icon(Heroicon::OutlinedQueueList)
            ->schema([
                Text::make(sprintf(
                    'Queues observed: %d · Pending %d · In flight %d · Delayed %d · Failed %d',
                    $queues['queue_count'],
                    $queues['total_pending'],
                    $queues['total_reserved'],
                    $queues['total_delayed'],
                    $queues['total_failed'],
                ))->color($queues['total_failed'] > 0 ? 'warning' : null),
                Text::make(sprintf(
                    'Oldest pending: %s · Oldest failed: %s',
                    $queues['oldest_pending_age_seconds'] === null ? 'none waiting' : $queues['oldest_pending_age_seconds'].'s',
                    $queues['oldest_failed_age_seconds'] === null ? 'none' : $queues['oldest_failed_age_seconds'].'s',
                )),
                Text::make('Workers expected: Not Monitored · Workers healthy: Not Monitored · Processed recently: Not Available')
                    ->color('warning'),
                Text::make('Queue depth is not evidence of worker liveness. Source: '.$queues['source'])->color('gray'),
            ]);
    }

    private function schedulerSection(OperationsOverviewService $overview): Section
    {
        $scheduler = $overview->scheduler();
        $heartbeat = $scheduler['heartbeat'];

        $heartbeatLabel = match (true) {
            ! $heartbeat['observed'] => 'Never Observed',
            $heartbeat['healthy'] => 'Fresh ('.$heartbeat['age_seconds'].'s ago)',
            default => 'Stale ('.$heartbeat['age_seconds'].'s ago)',
        };

        return Section::make('Scheduler')
            ->icon(Heroicon::OutlinedClock)
            ->schema([
                Text::make('Heartbeat: '.$heartbeatLabel)
                    ->color($heartbeat['healthy'] ? 'success' : 'danger'),
                Text::make(sprintf('Registered schedules: %d', $scheduler['registered_count'])),
                Text::make('Overdue schedules: Not Available · Recent failed runs: Execution History Not Available')
                    ->color('warning'),
                Text::make($scheduler['execution_history_reason'])->color('gray'),
            ]);
    }

    private function dataProtectionSection(OperationsOverviewService $overview): Section
    {
        $data = $overview->dataProtection();

        return Section::make('Data Protection')
            ->icon(Heroicon::OutlinedServerStack)
            ->schema([
                Text::make('Backup inventory: '.($data['backup_inventory_available'] ? 'Available' : 'Not Available'))
                    ->color($data['backup_inventory_available'] ? 'success' : 'warning'),
                Text::make('PITR: '.($data['pitr_verified'] ? 'Verified' : 'Unknown — Not Verified'))
                    ->color($data['pitr_verified'] ? 'success' : 'warning'),
                Text::make('Verified real restore: '.($data['verified_restore'] ? 'Yes' : 'Never'))
                    ->color($data['verified_restore'] ? 'success' : 'danger'),
                Text::make(sprintf(
                    'Target RPO: %s · Actual RPO: %s',
                    $data['target_rpo_seconds'] === null ? 'Not Configured' : $data['target_rpo_seconds'].'s',
                    $data['actual_rpo_label'],
                )),
                Text::make(sprintf(
                    'Target RTO: %s · Actual RTO: %s',
                    $data['target_rto_seconds'] === null ? 'Not Configured' : $data['target_rto_seconds'].'s',
                    $data['actual_rto_label'],
                )),
            ]);
    }

    private function releaseSection(OperationsOverviewService $overview): Section
    {
        $release = $overview->release();

        return Section::make('Release')
            ->icon(Heroicon::OutlinedRocketLaunch)
            ->schema([
                Text::make('Current SaaS release: Unknown — Not Integrated')->color('warning'),
                Text::make('Source commit of the running checkout: '.($release['source_commit'] ?? 'Unavailable')),
                Text::make('Version skew: Not Calculable')->color('warning'),
                Text::make($release['reason'])->color('gray'),
            ]);
    }

    private function fleetSection(OperationsOverviewService $overview): Section
    {
        $fleet = $overview->fleet();

        return Section::make('Fleet Migrations')
            ->icon(Heroicon::OutlinedRocketLaunch)
            ->schema([
                Text::make(sprintf(
                    'Active %d · Pending %d · Halted %d · Completed %d (all simulated)',
                    $fleet['active'],
                    $fleet['pending'],
                    $fleet['halted'],
                    $fleet['completed'],
                ))->color($fleet['halted'] > 0 ? 'warning' : null),
                Text::make('Canary results: Not Available — no canary stage exists.')->color('warning'),
                Text::make(sprintf(
                    'Production-safe orchestration: No — %d required safety control(s) are absent.',
                    $fleet['missing_controls'],
                ))->color('danger'),
            ]);
    }

    private function statusCommunicationsSection(OperationsOverviewService $overview): Section
    {
        $status = $overview->statusCommunications();

        return Section::make('Status Communications')
            ->icon(Heroicon::OutlinedMegaphone)
            ->schema([
                Text::make('Publication: '.$status['publication_semantics'])
                    ->color($status['is_publicly_published'] ? 'success' : 'danger'),
                Text::make(sprintf(
                    'Status records in a published state: %d (unresolved: %d)',
                    $status['published_records'],
                    $status['unresolved_published_records'],
                )),
                Text::make($status['disclosure'])->color('gray'),
            ]);
    }

    private function recentChangesSection(OperationsOverviewService $overview): Section
    {
        $changes = $overview->recentChanges();

        if ($changes === []) {
            return Section::make('Recent Operational Changes')
                ->icon(Heroicon::OutlinedClock)
                ->collapsible()
                ->schema([Text::make('No operational change has been recorded.')]);
        }

        return Section::make('Recent Operational Changes ('.count($changes).')')
            ->icon(Heroicon::OutlinedClock)
            ->collapsible()
            ->description('Assembled from existing operational records — health transitions, incidents, status records, fleet runs and restore drills. Not an audit-log dump.')
            ->schema(array_map(
                fn (array $change): Text => Text::make(sprintf(
                    '%s — [%s] %s',
                    $change['at']->toDayDateTimeString(),
                    $change['area'],
                    $change['summary'],
                )),
                $changes,
            ));
    }

    /**
     * The gaps. Separated from Requires Attention because nobody can
     * resolve these by responding — they are missing capabilities,
     * and they need to stay visible so coverage is never assumed.
     */
    private function coverageGapsSection(OperationsOverviewService $overview): Section
    {
        $gaps = $overview->coverageGaps();

        return Section::make('Coverage Gaps ('.count($gaps).')')
            ->icon(Heroicon::OutlinedEyeSlash)
            ->collapsible()
            ->description('Surfaces this platform knowingly cannot observe. These are not alerts — they cannot be cleared by responding to them.')
            ->schema(array_map(
                fn (array $gap): Text => Text::make('['.$gap['area'].'] '.$gap['gap'])->color('gray'),
                $gaps,
            ));
    }
}
