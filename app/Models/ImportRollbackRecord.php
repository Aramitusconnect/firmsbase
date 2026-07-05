<?php

namespace App\Models;

use App\Enums\RollbackRecordStatus;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportRollbackRecord extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'import_batch_id',
        'import_row_id',
        'rolled_back_record_type',
        'rolled_back_record_id',
        'status',
        'reason',
        'rolled_back_by_firm_user_id',
        'rolled_back_by_platform_admin_id',
        'rolled_back_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => RollbackRecordStatus::class,
            'rolled_back_at' => 'datetime',
        ];
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ImportBatch::class);
    }

    public function importRow(): BelongsTo
    {
        return $this->belongsTo(ImportRow::class);
    }

    public function rolledBackByFirmUser(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'rolled_back_by_firm_user_id');
    }

    public function rolledBackByPlatformAdmin(): BelongsTo
    {
        return $this->belongsTo(PlatformAdmin::class, 'rolled_back_by_platform_admin_id');
    }
}
