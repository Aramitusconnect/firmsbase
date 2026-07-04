<?php

namespace App\Services;

use App\Enums\SenderDomainStatus;
use App\Models\NotificationTemplate;
use Illuminate\Support\Facades\DB;

/**
 * SenderDomainVerificationService — reads/updates the stored SPF/
 * DKIM/DMARC verification fields on notification_templates (no
 * separate sender_domains table — approved clarification). No live
 * DNS lookups anywhere (approved clarification) — this service only
 * evaluates fields that some external, out-of-phase verification
 * process would set. markVerified()/markFailed() are the only writers.
 */
class SenderDomainVerificationService
{
    public function isVerified(NotificationTemplate $template): bool
    {
        return $template->isDomainVerified();
    }

    public function markVerified(NotificationTemplate $template): NotificationTemplate
    {
        $template->update([
            'spf_status' => SenderDomainStatus::Verified,
            'dkim_status' => SenderDomainStatus::Verified,
            'dmarc_status' => SenderDomainStatus::Verified,
            'domain_verified_at' => now(),
        ]);

        return $template->fresh();
    }

    public function markFailed(NotificationTemplate $template, ?string $reason = null): NotificationTemplate
    {
        $template->update([
            'spf_status' => SenderDomainStatus::Failed,
            'dkim_status' => SenderDomainStatus::Failed,
            'dmarc_status' => SenderDomainStatus::Failed,
            'domain_verified_at' => null,
        ]);

        return $template->fresh();
    }

    /**
     * Applies the same verification outcome to every template sharing
     * this firm+from_domain, so verifying a domain once doesn't
     * require touching each template row individually.
     */
    public function syncVerificationAcrossFirmTemplates(?int $firmId, string $fromDomain, bool $verified): int
    {
        return DB::table('notification_templates')
            ->where('firm_id', $firmId)
            ->where('from_domain', $fromDomain)
            ->update($verified
                ? [
                    'spf_status' => SenderDomainStatus::Verified->value,
                    'dkim_status' => SenderDomainStatus::Verified->value,
                    'dmarc_status' => SenderDomainStatus::Verified->value,
                    'domain_verified_at' => now(),
                ]
                : [
                    'spf_status' => SenderDomainStatus::Failed->value,
                    'dkim_status' => SenderDomainStatus::Failed->value,
                    'dmarc_status' => SenderDomainStatus::Failed->value,
                    'domain_verified_at' => null,
                ]
            );
    }
}
