<?php

declare(strict_types=1);

namespace App\Marketplace\Models;

use App\Marketplace\Enums\DirectoryImportRowStatus;
use App\Models\Concerns\HasPublicUuid;
use Database\Factories\DirectoryImportRowFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DirectoryImportRow — Mission 2 (MyAttorney Marketplace Core),
 * sections 53-55. See directory_import_batches' own docblock.
 */
class DirectoryImportRow extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'directory_import_batch_id',
        'row_number',
        'raw_data',
        'mapped_data',
        'status',
        'errors',
        'duplicate_of_directory_firm_id',
        'applied_directory_firm_id',
    ];

    protected function casts(): array
    {
        return [
            'raw_data' => 'array',
            'mapped_data' => 'array',
            'status' => DirectoryImportRowStatus::class,
            'errors' => 'array',
        ];
    }

    protected static function newFactory(): DirectoryImportRowFactory
    {
        return DirectoryImportRowFactory::new();
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(DirectoryImportBatch::class, 'directory_import_batch_id');
    }

    public function duplicateOfFirm(): BelongsTo
    {
        return $this->belongsTo(DirectoryFirm::class, 'duplicate_of_directory_firm_id');
    }

    public function appliedFirm(): BelongsTo
    {
        return $this->belongsTo(DirectoryFirm::class, 'applied_directory_firm_id');
    }
}
