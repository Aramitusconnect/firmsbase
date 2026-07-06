<?php

namespace App\Models;

use App\Enums\AiToolActionStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AiToolAction — every AI tool action is audited (project rule 10),
 * append-only (no updated_at). The only writer is
 * AiToolActionRecorderService.
 */
class AiToolAction extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $table = 'ai_tool_actions';

    const UPDATED_AT = null;

    protected $fillable = [
        'firm_id',
        'matter_id',
        'ai_usage_event_id',
        'tool_name',
        'input_snapshot_json',
        'output_snapshot_json',
        'was_constrained',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'input_snapshot_json' => 'array',
            'output_snapshot_json' => 'array',
            'was_constrained' => 'boolean',
            'status' => AiToolActionStatus::class,
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new \LogicException('ai_tool_actions is append-only and cannot be updated.');
        });

        static::deleting(function () {
            throw new \LogicException('ai_tool_actions is append-only and cannot be deleted.');
        });
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function usageEvent(): BelongsTo
    {
        return $this->belongsTo(AiUsageEvent::class, 'ai_usage_event_id');
    }
}
