<?php

namespace App\Models;

use App\Enums\TimeEntryStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * TimeEntry — `seconds` is a whole-second integer column; nothing in
 * this model ever writes a fractional value to it. All status
 * transitions (submit/approve/reject/mark-invoiced) live in
 * TimeEntryApprovalService, never here. No uuid — staff-facing only in
 * Phase 3.
 */
class TimeEntry extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'user_id',
        'matter_id',
        'client_id',
        'time_tracking_session_id',
        'seconds',
        'is_billable',
        'billing_rate_cents_snapshot',
        'description',
        'worked_on',
        'status',
        'approved_by',
        'approved_at',
        'rejected_reason',
    ];

    protected function casts(): array
    {
        return [
            'is_billable' => 'boolean',
            'worked_on' => 'date',
            'status' => TimeEntryStatus::class,
            'approved_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function timeTrackingSession(): BelongsTo
    {
        return $this->belongsTo(TimeTrackingSession::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function invoiceLine(): HasOne
    {
        return $this->hasOne(InvoiceLine::class);
    }

    public function isEligibleForInvoicing(): bool
    {
        return $this->status === TimeEntryStatus::Approved && $this->is_billable;
    }
}
