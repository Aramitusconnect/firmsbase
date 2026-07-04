<?php

namespace App\Models;

use App\Enums\ConsentChannel;
use App\Enums\NotificationTemplateStatus;
use App\Enums\SenderDomainStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * NotificationTemplate — global platform defaults with an optional
 * per-firm override (nullable firm_id), per approved decision. This
 * is ALSO where sender/domain verification lives (from_email/
 * from_domain/spf_status/dkim_status/dmarc_status/domain_verified_at)
 * — deliberately, per your clarification, since no separate
 * sender_domains table exists in the approved 15-table contract.
 * channel reuses Phase 1's ConsentChannel enum, not a new
 * NotificationChannel enum.
 */
class NotificationTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'firm_id',
        'key',
        'channel',
        'language',
        'status',
        'subject',
        'body',
        'from_email',
        'from_domain',
        'spf_status',
        'dkim_status',
        'dmarc_status',
        'domain_verified_at',
    ];

    protected function casts(): array
    {
        return [
            'channel' => ConsentChannel::class,
            'status' => NotificationTemplateStatus::class,
            'spf_status' => SenderDomainStatus::class,
            'dkim_status' => SenderDomainStatus::class,
            'dmarc_status' => SenderDomainStatus::class,
            'domain_verified_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function isGlobalDefault(): bool
    {
        return is_null($this->firm_id);
    }

    /**
     * The gate NotificationDispatchService checks before ever sending
     * from this template's from_domain. No live DNS lookups — reads
     * stored status fields only (approved clarification).
     */
    public function isDomainVerified(): bool
    {
        return ! is_null($this->domain_verified_at)
            && $this->spf_status === SenderDomainStatus::Verified
            && $this->dkim_status === SenderDomainStatus::Verified
            && $this->dmarc_status === SenderDomainStatus::Verified;
    }
}
