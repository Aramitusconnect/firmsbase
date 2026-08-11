<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WebauthnCredential — Mission 1B (Extreme Security Hardening). A
 * single registered WebAuthn/passkey authenticator for a Platform
 * Admin. Not tenant-owned (see RowLevelSecurityCoverageMappingService,
 * classified Global — scoped to platform_admin_id, never firm_id).
 *
 * `credential_id` and `public_key` are stored base64-encoded (the
 * WebAuthn spec's own values are raw binary) — a portable, inspectable
 * text representation rather than a binary column. Never store a
 * private key here: WebAuthn private key material never leaves the
 * authenticator by design, so there is nothing secret to protect in
 * this table beyond the public key, which is safe to store in
 * plaintext (it is, definitionally, public).
 */
class WebauthnCredential extends Model
{
    use HasFactory;

    protected $fillable = [
        'platform_admin_id',
        'name',
        'credential_id',
        'public_key',
        'attestation_type',
        'transports',
        'aaguid',
        'sign_count',
        'backup_eligible',
        'backup_status',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'transports' => 'array',
            'sign_count' => 'integer',
            'backup_eligible' => 'boolean',
            'backup_status' => 'boolean',
            'last_used_at' => 'datetime',
        ];
    }

    public function platformAdmin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class);
    }
}
