<?php

namespace Database\Factories;

use App\Enums\InvoiceStatus;
use App\Enums\InvoiceType;
use App\Models\Client;
use App\Models\Firm;
use App\Models\Invoice;
use App\Models\Matter;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function create($attributes = [], ?Model $parent = null)
    {
        if (! empty($attributes)) {
            return $this->state($attributes)->create([], $parent);
        }

        $results = $this->make($attributes, $parent);
        $models = $results instanceof Model ? new Collection([$results]) : $results;
        $service = new TenantContextService();

        $models->groupBy('firm_id')->each(function (Collection $group) use ($service) {
            $service->setDatabaseTenantContextForFirmId($group->first()->firm_id);
            $this->store($group);
        });

        $this->callAfterCreating($models, $parent);

        return $results;
    }

    /**
     * The invoice and its nested client are always tied to the SAME
     * firm — generating one firm here up front (rather than letting
     * firm_id and client_id resolve as two independent
     * Firm::factory()/Client::factory() calls) is deliberate: a bare
     * Invoice::factory()->create() with no state must never produce an
     * invoice whose client belongs to an unrelated firm, matching the
     * root-cause fix already applied to MatterFactory in Section
     * 39A-3F.
     */
    public function definition(): array
    {
        $firm = Firm::factory()->create();

        return [
            'firm_id' => $firm->id,
            'client_id' => Client::factory()->forFirm($firm),
            'matter_id' => null,
            'invoice_type' => InvoiceType::TimeAndExpense,
            'status' => InvoiceStatus::Draft,
            'currency' => 'usd',
            'subtotal_cents' => 0,
            'total_cents' => 0,
            'amount_paid_cents' => 0,
            'created_by' => null,
        ];
    }

    /**
     * Ties both the invoice AND its nested client to the given firm —
     * used when the caller already has a specific pre-existing firm to
     * attach to, rather than the fresh random one definition() would
     * otherwise generate.
     */
    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => [
            'firm_id' => $firm->id,
            'client_id' => Client::factory()->forFirm($firm),
        ]);
    }

    public function forClient(Client $client): static
    {
        return $this->state(fn () => [
            'firm_id' => $client->firm_id,
            'client_id' => $client->id,
        ]);
    }

    /**
     * Ties the invoice's firm_id AND client_id to the given matter's
     * own firm/client, and sets matter_id — mirrors forClient() but
     * additionally threads the matter relationship through, so a
     * caller who wants a matter-linked invoice never has to worry
     * about assembling a consistent firm/client/matter triple by hand.
     */
    public function forMatter(Matter $matter): static
    {
        return $this->state(fn () => [
            'firm_id' => $matter->firm_id,
            'client_id' => $matter->client_id,
            'matter_id' => $matter->id,
        ]);
    }

    public function status(InvoiceStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }

    public function totals(int $subtotalCents): static
    {
        return $this->state(fn () => ['subtotal_cents' => $subtotalCents, 'total_cents' => $subtotalCents]);
    }
}
