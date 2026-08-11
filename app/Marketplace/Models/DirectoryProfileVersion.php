<?php

declare(strict_types=1);

namespace App\Marketplace\Models;

use App\Marketplace\Enums\DataProvenanceSourceType;
use App\Marketplace\Enums\DirectoryPublicationState;
use App\Models\Concerns\HasPublicUuid;
use Database\Factories\DirectoryProfileVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DirectoryProfileVersion — Mission 2 (MyAttorney Marketplace Core),
 * section 25. See the migration's own docblock. Append-only —
 * MarketplaceProfileVersionService::record() is the sole write path.
 */
class DirectoryProfileVersion extends Model
{
    use HasFactory, HasPublicUuid;

    const UPDATED_AT = null;

    protected $fillable = [
        'directory_firm_id',
        'changed_fields',
        'actor_type',
        'actor_id',
        'source',
        'publication_state',
    ];

    protected function casts(): array
    {
        return [
            'changed_fields' => 'array',
            'source' => DataProvenanceSourceType::class,
            'publication_state' => DirectoryPublicationState::class,
        ];
    }

    protected static function newFactory(): DirectoryProfileVersionFactory
    {
        return DirectoryProfileVersionFactory::new();
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException(
                'directory_profile_versions is append-only: an existing row can never be updated.'
            );
        });

        static::deleting(function () {
            throw new \LogicException(
                'directory_profile_versions is append-only: an existing row can never be deleted.'
            );
        });
    }

    public function directoryFirm(): BelongsTo
    {
        return $this->belongsTo(DirectoryFirm::class);
    }
}
