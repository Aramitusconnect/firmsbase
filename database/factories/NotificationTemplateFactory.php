<?php

namespace Database\Factories;

use App\Enums\ConsentChannel;
use App\Enums\NotificationTemplateStatus;
use App\Enums\SenderDomainStatus;
use App\Models\Firm;
use App\Models\NotificationTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NotificationTemplate>
 */
class NotificationTemplateFactory extends Factory
{
    protected $model = NotificationTemplate::class;

    public function definition(): array
    {
        return [
            'firm_id' => null,
            'key' => 'document_reminder',
            'channel' => ConsentChannel::Email,
            'language' => 'en',
            'status' => NotificationTemplateStatus::Active,
            'subject' => 'A document is waiting on you',
            'body' => 'Please log in to your portal to upload the requested document.',
            'from_email' => null,
            'from_domain' => null,
            'spf_status' => SenderDomainStatus::Pending,
            'dkim_status' => SenderDomainStatus::Pending,
            'dmarc_status' => SenderDomainStatus::Pending,
            'domain_verified_at' => null,
        ];
    }

    public function forFirm(Firm $firm): static
    {
        return $this->state(fn () => ['firm_id' => $firm->id]);
    }

    public function domainVerified(string $fromEmail = 'notifications@firmsbase.test'): static
    {
        return $this->state(fn () => [
            'from_email' => $fromEmail,
            'from_domain' => explode('@', $fromEmail)[1] ?? 'firmsbase.test',
            'spf_status' => SenderDomainStatus::Verified,
            'dkim_status' => SenderDomainStatus::Verified,
            'dmarc_status' => SenderDomainStatus::Verified,
            'domain_verified_at' => now(),
        ]);
    }

    public function domainUnverified(string $fromEmail = 'notifications@unverified.test'): static
    {
        return $this->state(fn () => [
            'from_email' => $fromEmail,
            'from_domain' => explode('@', $fromEmail)[1] ?? 'unverified.test',
            'spf_status' => SenderDomainStatus::Pending,
            'dkim_status' => SenderDomainStatus::Pending,
            'dmarc_status' => SenderDomainStatus::Pending,
            'domain_verified_at' => null,
        ]);
    }
}
