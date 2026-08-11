<?php

declare(strict_types=1);

namespace App\Marketplace\Models;

use App\Marketplace\Enums\CorrectionState;
use App\Marketplace\Enums\CorrectionType;
use App\Models\Concerns\HasPublicUuid;
use App\Models\FirmUser;
use App\Models\PlatformAdmin;
use Database\Factories\DirectoryCorrectionRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * DirectoryCorrectionRequest — Mission 2 (MyAttorney Marketplace
 * Core), section 51. See the migration's own docblock. A "dumb" model
 * — every state transition/guard lives in MarketplaceCorrectionService,
 * matching DirectoryClaim's established convention.
 */
class DirectoryCorrectionRequest extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'directory_firm_id',
        'subject_type',
        'subject_id',
        'correction_type',
        'state',
        'description',
        'reporter_name',
        'reporter_email',
        'reporter_firm_user_id',
        'reviewer_notes',
        'resolution_notes',
        'rejection_reason',
        'decided_at',
        'decided_by_platform_admin_id',
    ];

    protected function casts(): array
    {
        return [
            'correction_type' => CorrectionType::class,
            'state' => CorrectionState::class,
            'decided_at' => 'datetime',
        ];
    }

    protected static function newFactory(): DirectoryCorrectionRequestFactory
    {
        return DirectoryCorrectionRequestFactory::new();
    }

    public function directoryFirm(): BelongsTo
    {
        return $this->belongsTo(DirectoryFirm::class);
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'reporter_firm_user_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'decided_by_platform_admin_id');
    }
}
