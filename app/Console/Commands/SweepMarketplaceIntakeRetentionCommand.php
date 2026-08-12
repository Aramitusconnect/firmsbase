<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\FirmActivationStatus;
use App\Enums\MarketplaceIntakeStatus;
use App\Marketplace\Models\MarketplaceIntake;
use App\Marketplace\Services\MarketplaceIntakeService;
use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * marketplace:intakes:retention:sweep — Mission 3 (MyAttorney
 * Conversion + AI Intake), checkpoint 14. The "abandoned-intake
 * retention sweep" the marketplace_intakes create-table migration's
 * own docblock already reserved this checkpoint for.
 *
 * Unlike marketplace:analytics:prune (a plain, non-tenant delete on a
 * platform-owned, no-RLS table), marketplace_intakes is FORCE RLS and
 * tenant-owned — this command loops every activated firm and evaluates
 * candidates inside that firm's own runWithFirmContext(), mirroring
 * SweepDocumentRequestRemindersCommand's own established per-firm sweep
 * shape. Never deletes a row — MarketplaceIntakeService::
 * purgeExpiredPii() scrubs identity fields only, preserving the row and
 * its own audit trail (see that method's own docblock for why).
 */
final class SweepMarketplaceIntakeRetentionCommand extends Command
{
    protected $signature = 'marketplace:intakes:retention:sweep {--dry-run}';

    protected $description = 'Scrubs prospect PII from terminal, never-converted MarketplaceIntake rows past the configured retention window.';

    public function __construct(private readonly MarketplaceIntakeService $intakeService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $cutoff = Carbon::now()->subDays((int) config('marketplace.intake_retention_days'));
        $dryRun = (bool) $this->option('dry-run');
        $total = 0;

        Firm::query()
            ->where('activation_status', FirmActivationStatus::Activated)
            ->cursor()
            ->each(function (Firm $firm) use ($cutoff, $dryRun, &$total) {
                $total += $this->sweepFirm($firm, $cutoff, $dryRun);
            });

        $verb = $dryRun ? 'Would purge' : 'Purged';
        $this->info("{$verb} {$total} intake row(s) older than {$cutoff->toDateString()}.");

        return self::SUCCESS;
    }

    private function sweepFirm(Firm $firm, Carbon $cutoff, bool $dryRun): int
    {
        return (new TenantContextService)->runWithFirmContext($firm, function () use ($firm, $cutoff, $dryRun) {
            $candidates = MarketplaceIntake::query()
                ->whereIn('status', [
                    MarketplaceIntakeStatus::Declined,
                    MarketplaceIntakeStatus::Abandoned,
                    MarketplaceIntakeStatus::Expired,
                ])
                ->whereNull('purged_at')
                ->where('updated_at', '<', $cutoff)
                ->get();

            if (! $dryRun) {
                foreach ($candidates as $intake) {
                    $this->intakeService->purgeExpiredPii($firm, $intake);
                }
            }

            return $candidates->count();
        });
    }
}
