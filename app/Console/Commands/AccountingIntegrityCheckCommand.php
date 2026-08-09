<?php

namespace App\Console\Commands;

use App\Models\Firm;
use App\Services\AccountingIntegrityService;
use Illuminate\Console\Command;

/**
 * `accounting:integrity-check` — Accounting Integrity Hardening Pass,
 * item 10. Read-only, always. This command never writes anything — not
 * to accounting_journal_entries, not to any other table — it only
 * reports what AccountingIntegrityService found. Resolving a finding
 * always means a human investigates and corrects it through the
 * normal, already-audited service layer, never through this command.
 */
class AccountingIntegrityCheckCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'accounting:integrity-check
        {--firm= : Only check the firm with this id}';

    /**
     * @var string
     */
    protected $description = 'Read-only sweep for accounting-journal/domain-data inconsistencies (missing postings, unbalanced entries, over-allocated payments, closed-period violations). Never fixes anything.';

    public function handle(AccountingIntegrityService $service): int
    {
        $firmId = $this->option('firm');

        $reports = $firmId !== null
            ? collect([$service->checkFirm(Firm::query()->findOrFail((int) $firmId))])
            : $service->checkAllFirms();

        $totalFindings = 0;

        foreach ($reports as $report) {
            if ($report->isClean()) {
                continue;
            }

            $this->components->warn("Firm #{$report->firmId}: {$report->findings->count()} finding(s)");

            $rows = $report->findings->map(fn ($finding) => [
                $finding->type,
                $finding->subjectType,
                (string) $finding->subjectId,
                $finding->description,
            ])->all();

            $this->table(['Type', 'Subject', 'Subject ID', 'Description'], $rows);

            $totalFindings += $report->findings->count();
        }

        if ($totalFindings === 0) {
            $this->components->info('No accounting integrity findings.');

            return self::SUCCESS;
        }

        $this->components->error("{$totalFindings} total finding(s) across ".$reports->filter(fn ($r) => ! $r->isClean())->count().' firm(s). Investigate and correct each through its normal service layer — this command never auto-fixes anything.');

        return self::FAILURE;
    }
}
