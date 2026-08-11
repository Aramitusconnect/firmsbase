<?php

declare(strict_types=1);

namespace App\Marketplace\Models;

use App\Marketplace\Enums\DataProvenanceSourceType;
use App\Marketplace\Enums\DirectoryAttorneyFirmRelationshipState;
use Database\Factories\DirectoryAttorneyFirmFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DirectoryAttorneyFirm — Mission 2 (MyAttorney Marketplace Core),
 * section 11. See
 * database/migrations/2026_11_10_100004_create_directory_attorney_firm_table.php
 * for the full rationale. A real model, not an anonymous pivot — it
 * carries its own state/title/date columns and factual meaning beyond
 * "these two rows are linked."
 */
class DirectoryAttorneyFirm extends Model
{
    use HasFactory;

    protected $table = 'directory_attorney_firm';

    protected $fillable = [
        'directory_attorney_id',
        'directory_firm_id',
        'firm_office_id',
        'relationship_state',
        'title',
        'is_primary_firm',
        'started_at',
        'ended_at',
        'source_type',
        'source_reference',
    ];

    protected function casts(): array
    {
        return [
            'relationship_state' => DirectoryAttorneyFirmRelationshipState::class,
            'is_primary_firm' => 'boolean',
            'started_at' => 'date',
            'ended_at' => 'date',
            'source_type' => DataProvenanceSourceType::class,
        ];
    }

    protected static function newFactory(): DirectoryAttorneyFirmFactory
    {
        return DirectoryAttorneyFirmFactory::new();
    }

    public function attorney(): BelongsTo
    {
        return $this->belongsTo(DirectoryAttorney::class, 'directory_attorney_id');
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(DirectoryFirm::class, 'directory_firm_id');
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(FirmOffice::class, 'firm_office_id');
    }
}
