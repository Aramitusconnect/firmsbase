<?php

namespace App\Models;

use App\Enums\HashAlgorithm;
use App\Enums\SignatureSourceDocumentType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DocumentHash — immutable append-only evidentiary row. hash_value is
 * CALLER-SUPPLIED (see DocumentHashService docblock) — this model does
 * not compute a hash from real file bytes, since no real file storage
 * pipeline exists anywhere in this codebase yet.
 */
class DocumentHash extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'firm_id',
        'source_document_type',
        'document_id',
        'generated_document_id',
        'algorithm',
        'hash_value',
        'recorded_by_firm_user_id',
        'recorded_at',
    ];

    protected function casts(): array
    {
        return [
            'source_document_type' => SignatureSourceDocumentType::class,
            'algorithm' => HashAlgorithm::class,
            'recorded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (self $hash) {
            if ($hash->exists) {
                throw new \LogicException(
                    'document_hashes rows are immutable — an existing row can never be updated.'
                );
            }
        });

        static::deleting(function (self $hash) {
            throw new \LogicException(
                'document_hashes rows are immutable — an existing row can never be deleted.'
            );
        });
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function generatedDocument(): BelongsTo
    {
        return $this->belongsTo(GeneratedDocument::class);
    }

    public function recordedByFirmUser(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'recorded_by_firm_user_id');
    }
}
