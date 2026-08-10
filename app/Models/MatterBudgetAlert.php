<?php

namespace App\Models;

use App\Enums\MatterBudgetAlertSeverity;
use App\Enums\MatterBudgetAlertType;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * MatterBudgetAlert — Predictive Matter Budget Alerts, item 12/13/15.
 * See its own create-table migration for the full dedup-checkpoint
 * rationale. Only the fact/evidence portion is ever set at creation;
 * `resolved_at` is the one field a later process may update.
 */
class MatterBudgetAlert extends Model
{
    use BelongsToTenant, HasFactory;

    private const MUTABLE_FIELDS = ['resolved_at'];

    protected $fillable = [
        'firm_id',
        'matter_id',
        'matter_budget_id',
        'alert_type',
        'metric_key',
        'severity',
        'threshold_percent_crossed',
        'metric_snapshot_json',
        'domain_event_id',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'alert_type' => MatterBudgetAlertType::class,
            'severity' => MatterBudgetAlertSeverity::class,
            'threshold_percent_crossed' => 'integer',
            'metric_snapshot_json' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $alert) {
            $dirtyKeys = array_keys($alert->getDirty());
            $disallowed = array_diff($dirtyKeys, self::MUTABLE_FIELDS);

            if (! empty($disallowed)) {
                throw new \LogicException(
                    'matter_budget_alerts may only update resolved_at. Disallowed dirty field(s): '.implode(', ', $disallowed)
                );
            }
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

    public function matterBudget(): BelongsTo
    {
        return $this->belongsTo(MatterBudget::class);
    }

    public function domainEvent(): BelongsTo
    {
        return $this->belongsTo(DomainEvent::class);
    }

    public function isOpen(): bool
    {
        return $this->resolved_at === null;
    }
}
