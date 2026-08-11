<?php

declare(strict_types=1);

namespace App\Models;

use App\Marketplace\Models\DirectoryAttorney;
use App\Marketplace\Models\DirectoryFirm;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Language — Mission 2 (MyAttorney Marketplace Core), section 14. A
 * genuinely new global reference table — repository audit confirmed
 * no Language model exists anywhere (`firm_settings.default_language`/
 * `clients.preferred_language` remain plain string(10) columns,
 * untouched by this addition). GLOBAL platform catalog, modeled
 * directly on PracticeArea's own shape: no BelongsToTenant, no uuid,
 * addressed by `code` (ISO 639-1).
 */
class Language extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'code';
    }

    public function directoryFirms(): BelongsToMany
    {
        return $this->belongsToMany(DirectoryFirm::class, 'directory_firm_languages')
            ->withPivot('source_type')
            ->withTimestamps();
    }

    public function directoryAttorneys(): BelongsToMany
    {
        return $this->belongsToMany(DirectoryAttorney::class, 'directory_attorney_languages')
            ->withPivot('source_type')
            ->withTimestamps();
    }
}
