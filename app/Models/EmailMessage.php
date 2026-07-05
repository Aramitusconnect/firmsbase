<?php

namespace App\Models;

use App\Enums\EmailBodyStatus;
use App\Enums\EmailMessageDirection;
use App\Enums\EmailStorageMode;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * EmailMessage — never plaintext body/body_html/body_text (project
 * rule) — encrypted_body_ciphertext + encryption_key_id are the only
 * body storage columns, populated only when storage_mode captured
 * EncryptedBody/EncryptedBodyAndAttachments and body_status is
 * Encrypted. This model never decrypts encrypted_body_ciphertext
 * itself — only EmailBodyEncryptionService does, in memory.
 */
class EmailMessage extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'email_account_id',
        'provider_thread_id',
        'provider_message_id',
        'direction',
        'from_address',
        'to_addresses',
        'subject',
        'sent_at',
        'received_at',
        'storage_mode',
        'body_status',
        'encrypted_body_ciphertext',
        'encryption_key_id',
        'has_attachments',
    ];

    protected $hidden = [
        'encrypted_body_ciphertext',
    ];

    protected function casts(): array
    {
        return [
            'to_addresses' => 'array',
            'direction' => EmailMessageDirection::class,
            'storage_mode' => EmailStorageMode::class,
            'body_status' => EmailBodyStatus::class,
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
            'has_attachments' => 'boolean',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function emailAccount(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class);
    }

    public function encryptionKey(): BelongsTo
    {
        return $this->belongsTo(TenantEncryptionKey::class, 'encryption_key_id');
    }

    public function links(): HasMany
    {
        return $this->hasMany(EmailMessageLink::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(EmailAttachment::class);
    }
}
