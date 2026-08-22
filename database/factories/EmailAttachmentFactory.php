<?php

namespace Database\Factories;

use App\Enums\EmailAttachmentPromotionStatus;
use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use App\Services\TenantContextService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * @extends Factory<EmailAttachment>
 */
class EmailAttachmentFactory extends Factory
{
    protected $model = EmailAttachment::class;

    /**
     * email_attachments has permanent FORCE ROW LEVEL SECURITY (see
     * database/migrations/2026_08_27_950027_prepare_row_level_security_
     * and_force_rls_on_email_attachments_table.php), so every INSERT
     * (test or app) must run under the row's own app.current_firm_id
     * context. This bare (default) creation path is already
     * tenant-consistent (definition()'s firm_id is a lazy closure that
     * reads the created EmailMessage's own firm_id, so the two can
     * never disagree), so this override adds ONLY the generic
     * context-hold — no root-cause definition() fix is needed. Mirrors
     * MatterExpenseFactory::create() exactly, including deliberately
     * leaving the database tenant context active afterward rather than
     * clearing it.
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
            'email_message_id' => EmailMessage::factory(),
            'firm_id' => fn (array $attributes) => EmailMessage::query()->find($attributes['email_message_id'])->firm_id,
            'original_filename' => $this->faker->word().'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => $this->faker->numberBetween(1000, 500000),
            'provider_attachment_id' => 'att-'.$this->faker->uuid(),
            'scan_status' => 'pending',
            'simulated_storage_path' => fn (array $attributes) => "email-attachments/fixture/{$attributes['firm_id']}/".$this->faker->uuid(),
            'document_id' => null,
            'promotion_status' => EmailAttachmentPromotionStatus::Pending->value,
            'blocked_reason' => null,
        ];
    }

    public function forMessage(EmailMessage $message): static
    {
        return $this->state(fn () => [
            'email_message_id' => $message->id,
            'firm_id' => $message->firm_id,
        ]);
    }

    /**
     * Named so FakeVirusScanner's marker-string convention (path
     * contains "infected") deterministically produces an Infected scan
     * outcome when EmailAttachmentSafetyService runs.
     */
    public function infectedFixture(): static
    {
        return $this->state(fn () => [
            'simulated_storage_path' => fn (array $attributes) => "email-attachments/fixture/{$attributes['firm_id']}/infected-".$this->faker->uuid(),
        ]);
    }
}
