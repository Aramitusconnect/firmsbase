<?php

namespace App\Models;

use App\Enums\EmailOAuthTokenStatus;
use App\Enums\EmailOAuthTokenType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * EmailOAuthToken — no firm_id column, no BelongsToTenant (scoped
 * transitively through email_account_id, same reasoning as Phase 8's
 * ImportRow). No uuid — secret material, never referenced externally.
 * encrypted_token_ciphertext is never cast to plaintext by this model
 * — only EmailOAuthTokenService may decrypt it, in memory, for the
 * duration of a single call.
 */
class EmailOAuthToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'email_account_id',
        'token_type',
        'encrypted_token_ciphertext',
        'encryption_key_id',
        'status',
        'expires_at',
    ];

    protected $hidden = [
        'encrypted_token_ciphertext',
    ];

    protected function casts(): array
    {
        return [
            'token_type' => EmailOAuthTokenType::class,
            'status' => EmailOAuthTokenStatus::class,
            'expires_at' => 'datetime',
        ];
    }

    public function emailAccount(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class);
    }

    public function encryptionKey(): BelongsTo
    {
        return $this->belongsTo(TenantEncryptionKey::class, 'encryption_key_id');
    }
}
