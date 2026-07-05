<?php

namespace App\Models;

use App\Enums\SignatureEventActorType;
use App\Enums\SignatureEventType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SignatureEvent — the append-only evidentiary ledger row. No uuid, no
 * BelongsToTenant (firm_id kept for direct queries only, mirroring
 * Phase 10's FormReviewEvent/GeneratedDocumentEvent precedent). Fully
 * immutable from creation: the booted() guard below blocks ANY update
 * or delete on an existing row — the strictest reading of "signature
 * evidence must be immutable or append-only after completion" (here:
 * immutable from the moment it's created, not just after the request
 * completes). acknowledger_type/acknowledger_id/text_version/
 * acknowledged/acknowledged_at are the literal Phase 6
 * AcknowledgmentRecord field names, populated only on
 * event_type=consent_captured rows (see SignatureEventLogger).
 */
class SignatureEvent extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'firm_id',
        'signature_request_id',
        'signature_request_recipient_id',
        'event_type',
        'actor_type',
        'actor_firm_user_id',
        'actor_recipient_id',
        'ip_address',
        'user_agent',
        'document_hash_id',
        'acknowledger_type',
        'acknowledger_id',
        'text_version',
        'acknowledged',
        'acknowledged_at',
        'metadata_json',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => SignatureEventType::class,
            'actor_type' => SignatureEventActorType::class,
            'acknowledged' => 'boolean',
            'acknowledged_at' => 'datetime',
            'metadata_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $event) {
            if ($event->exists) {
                throw new \LogicException(
                    'signature_events rows are append-only and immutable — an existing row can never be updated.'
                );
            }
        });

        static::deleting(function (self $event) {
            throw new \LogicException(
                'signature_events rows are append-only and immutable — an existing row can never be deleted.'
            );
        });
    }

    public function signatureRequest(): BelongsTo
    {
        return $this->belongsTo(SignatureRequest::class);
    }

    public function signatureRequestRecipient(): BelongsTo
    {
        return $this->belongsTo(SignatureRequestRecipient::class);
    }

    public function actorFirmUser(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'actor_firm_user_id');
    }

    public function actorRecipient(): BelongsTo
    {
        return $this->belongsTo(SignatureRequestRecipient::class, 'actor_recipient_id');
    }

    public function documentHash(): BelongsTo
    {
        return $this->belongsTo(DocumentHash::class);
    }
}
