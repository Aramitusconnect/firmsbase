<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ExpenseCategory;
use App\Models\Firm;
use App\Models\LeadSource;
use App\Services\FirmDefaultReferenceDataService;
use App\Services\TenantContextService;
use Illuminate\Console\Command;

/**
 * InitializeDefaultFirmReferenceDataCommand — FirmsVault staging
 * follow-up addition ("Application Completion — Catalogs + Firm-Owned
 * Reference Data"). The repair command for EXISTING firms provisioned
 * before FirmDefaultReferenceDataService existed (new firms already
 * receive these defaults automatically at provisioning time — see
 * FirmProvisioningService's own call site). Mirrors
 * ReportMissingPurchasedSeatsCommand's exact default-dry-run/--firm/
 * --apply shape.
 *
 * Idempotent by construction (delegates entirely to
 * FirmDefaultReferenceDataService, which never duplicates an existing
 * name/code and never overwrites a firm's own custom category/source —
 * it only INSERTS rows that are missing, never touches an existing
 * row's fields at all).
 */
class InitializeDefaultFirmReferenceDataCommand extends Command
{
    protected $signature = 'firms:initialize-default-reference-data
        {--firm= : Firm id to report on, or (with --apply) to actually seed}
        {--apply : Apply mode — actually insert the missing defaults for --firm. Requires --firm; never runs across all firms at once.}';

    protected $description = 'Report which firms are missing their default Expense Categories / Lead Sources (default/dry-run), or seed them for one explicitly-named firm (--apply --firm=<id>). Never duplicates existing records, never overwrites a firm\'s own custom categories/sources.';

    public function __construct(
        private readonly FirmDefaultReferenceDataService $defaultReferenceDataService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        return $this->option('apply') ? $this->apply() : $this->report();
    }

    private function report(): int
    {
        $firmIdOption = $this->option('firm');
        $tenantContext = new TenantContextService;

        $rows = [];

        $query = Firm::query()->orderBy('id');

        if (filled($firmIdOption)) {
            $query->where('id', (int) $firmIdOption);
        }

        $query->chunkById(100, function ($firms) use (&$rows, $tenantContext): void {
            foreach ($firms as $firm) {
                [$expenseCategoryCount, $leadSourceCount] = $tenantContext->runWithFirmContext($firm, function () use ($firm): array {
                    return [
                        ExpenseCategory::query()->where('firm_id', $firm->id)->count(),
                        LeadSource::query()->where('firm_id', $firm->id)->count(),
                    ];
                });

                $missingExpenseCategories = max(0, 15 - $expenseCategoryCount);
                $missingLeadSources = max(0, 12 - $leadSourceCount);

                if ($missingExpenseCategories === 0 && $missingLeadSources === 0) {
                    continue;
                }

                $rows[] = [
                    'firm_id' => $firm->id,
                    'firm_name' => $firm->name,
                    'expense_category_count' => $expenseCategoryCount,
                    'lead_source_count' => $leadSourceCount,
                ];
            }
        });

        if ($rows === []) {
            $this->components->info('No firm is missing default reference data (or every firm already has at least as many categories/sources as the default lists provide).');

            return self::SUCCESS;
        }

        $this->components->warn(count($rows).' firm(s) may be missing some default reference data:');

        $this->table(
            ['Firm ID', 'Firm', 'Expense Categories', 'Lead Sources'],
            collect($rows)->map(fn (array $r): array => [
                $r['firm_id'], $r['firm_name'], $r['expense_category_count'], $r['lead_source_count'],
            ])->all(),
        );

        $this->newLine();
        $this->components->info('This is a coarse count-based signal, not proof of which exact defaults are missing — FirmDefaultReferenceDataService itself decides that idempotently by name/code at apply time. Seed one firm at a time: php artisan firms:initialize-default-reference-data --apply --firm=<id>');

        return self::SUCCESS;
    }

    private function apply(): int
    {
        $firmId = $this->option('firm');

        if (! filled($firmId)) {
            $this->components->error('--apply requires --firm=<id> (exactly one firm at a time).');

            return self::FAILURE;
        }

        $firm = Firm::query()->find((int) $firmId);

        if ($firm === null) {
            $this->components->error("No firm found with id [{$firmId}].");

            return self::FAILURE;
        }

        $result = $this->defaultReferenceDataService->seedAllDefaults($firm);

        $addedExpenseCategories = $result['expense_categories'];
        $addedLeadSources = $result['lead_sources'];

        if ($addedExpenseCategories === [] && $addedLeadSources === []) {
            $this->components->info("Firm [{$firm->name}] (id {$firm->id}) already has every default expense category and lead source. Nothing to do.");

            return self::SUCCESS;
        }

        if ($addedExpenseCategories !== []) {
            $this->components->info('Added expense categories: '.implode(', ', $addedExpenseCategories));
        }

        if ($addedLeadSources !== []) {
            $this->components->info('Added lead sources: '.implode(', ', $addedLeadSources));
        }

        $this->components->info("Firm [{$firm->name}] (id {$firm->id}) default reference data initialized.");

        return self::SUCCESS;
    }
}
