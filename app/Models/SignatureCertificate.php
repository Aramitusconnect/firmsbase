<?php

namespace App\Models;

use App\Enums\SignatureCertificateStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SignatureCertificate — one immutable row per completed
 * SignatureRequest, enforced by a DB-unique signature_request_id (see
 * migration) as well as the booted() guard below blocking any update/
 * delete after creation. certificate_data_json is the serialized
 * SignatureEvidenceSnapshot, assembled once by SignatureCertificateService
 * and never rewritten.
 */
class SignatureCertificate extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    public $timestamps = false;

    protected $fillable = [
        'firm_id',
        'signature_request_id',
        'status',
        'certificate_data_json',
        'document_hash_id',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => SignatureCertificateStatus::class,
            'certificate_data_json' => 'array',
            'generated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $certificate) {
            if ($certificate->exists) {
                throw new \LogicException(
                    'signature_certificates rows are immutable after generation — an existing row can never be updated.'
                );
            }
        });

        static::deleting(function (self $certificate) {
            throw new \LogicException(
                'signature_certificates rows are immutable after generation — an existing row can never be deleted.'
            );
        });
    }

    public function signatureRequest(): BelongsTo
    {
        return $this->belongsTo(SignatureRequest::class);
    }

    public function documentHash(): BelongsTo
    {
        return $this->belongsTo(DocumentHash::class);
    }
}
