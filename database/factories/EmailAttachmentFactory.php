<?php

namespace Database\Factories;

use App\Enums\EmailAttachmentPromotionStatus;
use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmailAttachment>
 */
class EmailAttachmentFactory extends Factory
{
    protected $model = EmailAttachment::class;

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
