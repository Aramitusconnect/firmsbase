<?php

namespace Database\Factories;

use App\Enums\AccountingJournalSourceType;
use App\Models\AccountingJournalEntry;
use App\Models\Firm;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<AccountingJournalEntry>
 */
class AccountingJournalEntryFactory extends Factory
{
    protected $model = AccountingJournalEntry::class;

    /**
     * accounting_journal_entries has permanent FORCE ROW LEVEL SECURITY
     * (see the 2026_10_25_100003 activation migration), so every
     * INSERT (test or app) must run under the row's own
     * app.current_firm_id context — mirrors TrustLedgerEntryFactory's
     * own create() override exactly.
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
            'entry_date' => now()->toDateString(),
            'description' => $this->faker->sentence(),
            'source_type' => AccountingJournalSourceType::Adjustment,
            'created_at' => now(),
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function sourceType(AccountingJournalSourceType $type): static
    {
        return $this->state(fn () => ['source_type' => $type]);
    }
}
