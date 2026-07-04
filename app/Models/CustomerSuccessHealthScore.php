<?php

namespace App\Models;

use App\Enums\CustomerHealthRiskLevel;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * CustomerSuccessHealthScore — a point-in-time snapshot row (one new
 * row per computation, mirrors UsageRollup), not a single mutable row
 * per firm. Every numeric column here is a safe aggregate count — this
 * model never carries document content, matter notes, or message
 * bodies.
 */
class CustomerSuccessHealthScore extends Model
{
    use HasFactory, HasPublicUuid;

    protected $fillable = [
        'firm_id',
        'computed_at',
        'score',
        'risk_level',
        'onboarding_progress_percent',
        'last_login_at',
        'active_users_count',
        'matters_count',
        'clients_count',
        'documents_count',
        'invoices_count',
        'payment_plans_count',
        'payments_count',
        'ai_usage_count',
        'storage_bytes',
        'failed_jobs_count',
        'open_tickets_count',
        'subscription_status',
        'risk_flags',
    ];

    protected function casts(): array
    {
        return [
            'computed_at' => 'datetime',
            'risk_level' => CustomerHealthRiskLevel::class,
            'last_login_at' => 'datetime',
            'risk_flags' => 'array',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }
}
