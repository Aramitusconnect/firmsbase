<?php

namespace Database\Factories;

use App\Enums\EmailBodyStatus;
use App\Enums\EmailMessageDirection;
use App\Enums\EmailStorageMode;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<EmailMessage>
 */
class EmailMessageFactory extends Factory
{
    protected $model = EmailMessage::class;

    /**
     * email_messages has permanent FORCE ROW LEVEL SECURITY (see
     * database/migrations/2026_08_27_950026_prepare_row_level_security_
     * and_force_rls_on_email_messages_table.php), so every INSERT (test
     * or app) must run under the row's own app.current_firm_id context.
     * This bare (default) creation path is already tenant-consistent
     * (definition()'s firm_id is a lazy closure that reads the created
     * EmailAccount's own firm_id, so the two can never disagree), so
     * this override adds ONLY the generic context-hold — no root-cause
     * definition() fix is needed. Mirrors MatterExpenseFactory::create()
     * exactly, including deliberately leaving the database tenant
     * context active afterward rather than clearing it.
     */
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

    public function definition(): array
    {
        return [
            'email_account_id' => EmailAccount::factory(),
            'firm_id' => fn (array $attributes) => EmailAccount::query()->find($attributes['email_account_id'])->firm_id,
            'provider_thread_id' => 'thread-'.$this->faker->uuid(),
            'provider_message_id' => 'msg-'.$this->faker->uuid(),
            'direction' => EmailMessageDirection::Inbound->value,
            'from_address' => $this->faker->safeEmail(),
            'to_addresses' => [$this->faker->safeEmail()],
            'subject' => $this->faker->sentence(4),
            'sent_at' => now()->subMinutes(10),
            'received_at' => now()->subMinutes(9),
            'storage_mode' => EmailStorageMode::MetadataOnly->value,
            'body_status' => EmailBodyStatus::NotStored->value,
            'encrypted_body_ciphertext' => null,
            'encryption_key_id' => null,
            'has_attachments' => false,
        ];
    }

    public function forAccount(EmailAccount $account): static
    {
        return $this->state(fn () => [
            'firm_id' => $account->firm_id,
            'email_account_id' => $account->id,
        ]);
    }
}
