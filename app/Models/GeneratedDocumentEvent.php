<?php

namespace App\Models;

use App\Enums\GeneratedDocumentEventType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * GeneratedDocumentEvent — pure audit row, mirrors FormReviewEvent.
 */
class GeneratedDocumentEvent extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'firm_id',
        'generated_document_id',
        'event_type',
        'actor_firm_user_id',
        'notes',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => GeneratedDocumentEventType::class,
            'created_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function generatedDocument(): BelongsTo
    {
        return $this->belongsTo(GeneratedDocument::class);
    }

    public function actorFirmUser(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'actor_firm_user_id');
    }
}
