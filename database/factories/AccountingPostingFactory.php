<?php

namespace Database\Factories;

use App\Models\AccountingJournalEntry;
use App\Models\AccountingPosting;
use App\Models\ChartOfAccount;
use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<AccountingPosting>
 */
class AccountingPostingFactory extends Factory
{
    protected $model = AccountingPosting::class;

    /**
     * accounting_postings has permanent FORCE ROW LEVEL SECURITY (see
     * the 2026_10_25_100004 activation migration), so every INSERT
     * (test or app) must run under the row's own app.current_firm_id
     * context — mirrors TrustLedgerEntryFactory's own create() override
     * exactly.
     */
    public function create($attributes = [], ?Model $parent = null)
    {
        if (! empty($attributes)) {
            return $this->state($attributes)->create([], $parent);
        }

        $results = $this->make($attributes, $parent);
        $models = $results instanceof Model ? new Collection([$results]) : $results;
        $service = new TenantContextService;

        $models->groupBy('firm_id')->each(function (Collection $group) use ($service) {
            $service->setDatabaseTenantContextForFirmId($group->first()->firm_id);
            $this->store($group);
        });

        $this->callAfterCreating($models, $parent);

        return $results;
    }

    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'accounting_journal_entry_id' => AccountingJournalEntry::factory(),
            'chart_of_account_id' => ChartOfAccount::factory(),
            'debit_cents' => 0,
            'credit_cents' => 0,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function debit(int $amountCents): static
    {
        return $this->state(fn () => ['debit_cents' => $amountCents, 'credit_cents' => 0]);
    }

    public function credit(int $amountCents): static
    {
        return $this->state(fn () => ['debit_cents' => 0, 'credit_cents' => $amountCents]);
    }
}
