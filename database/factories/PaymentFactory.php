<?php

namespace Database\Factories;

use App\Enums\ManualPaymentMethod;
use App\Enums\PaymentClassification;
use App\Enums\PaymentStatus;
use App\Models\Client;
use App\Models\Firm;
use App\Models\Invoice;
use App\Models\Matter;
use App\Models\Payment;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

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

    /**
     * The payment and its nested client are always tied to the SAME
     * firm.
     *
     * Audit fix (eager-factory-side-effects audit): this used to call
     * Firm::factory()->create() as a plain PHP statement at the top of
     * definition() — a real, committed Firm every single time, even
     * when forFirm()/forClient()/forMatter()/forInvoice() below
     * immediately override firm_id/client_id with a caller-supplied
     * firm. Fixed by making firm_id Laravel's own lazy
     * factory-relationship form; client_id remains a lazy, uncreated
     * Factory instance derived from it.
     */
    public function definition(): array
    {
        return [
            'firm_id' => Firm::factory(),
            'client_id' => fn (array $attributes) => Client::factory()
                ->forFirm(Firm::query()->findOrFail($attributes['firm_id'])),
            'matter_id' => null,
            'invoice_id' => null,
            'payment_plan_installment_id' => null,
            'amount_cents' => 10000,
            'currency' => 'usd',
            'payment_method' => ManualPaymentMethod::Check,
            'payment_classification' => PaymentClassification::OperatingPayment,
            'status' => PaymentStatus::Succeeded,
            'external_reference' => null,
            'idempotency_key' => null,
            'rejection_reason' => null,
            'recorded_by' => null,
        ];
    }

    /**
     * Ties both the payment AND its nested client to the given firm —
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
     * Ties the payment's firm_id AND client_id to the given matter's
     * own firm/client, and sets matter_id — mirrors InvoiceFactory's
     * forMatter() so a caller who wants a matter-linked payment never
     * has to assemble a consistent firm/client/matter triple by hand.
     */
    public function forMatter(Matter $matter): static
    {
        return $this->state(fn () => [
            'firm_id' => $matter->firm_id,
            'client_id' => $matter->client_id,
            'matter_id' => $matter->id,
        ]);
    }

    /**
     * Ties the payment's firm_id AND client_id to the given invoice's
     * own firm/client, and sets invoice_id (plus matter_id when the
     * invoice itself is matter-linked) — mirrors forMatter() above.
     */
    public function forInvoice(Invoice $invoice): static
    {
        return $this->state(fn () => [
            'firm_id' => $invoice->firm_id,
            'client_id' => $invoice->client_id,
            'matter_id' => $invoice->matter_id,
            'invoice_id' => $invoice->id,
        ]);
    }

    public function blocked(string $reason = 'Explicitly classified as blocked.'): static
    {
        return $this->state(fn () => [
            'payment_classification' => PaymentClassification::BlockedPayment,
            'status' => PaymentStatus::Blocked,
            'rejection_reason' => $reason,
        ]);
    }

    public function idempotencyKey(string $key): static
    {
        return $this->state(fn () => ['idempotency_key' => $key]);
    }
}
