<?php

namespace App\Models;

use App\Enums\LegalHoldScope;
use App\Enums\LegalHoldStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * LegalHold — firm_id is non-nullable (every hold belongs to exactly
 * one firm, even Firm-scope holds), so this model DOES use
 * BelongsToTenant, matching the ExportJob precedent. Mutable: status
 * transitions Active -> Released via LegalHoldService::release().
 */
class LegalHold extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'scope_type',
        'client_id',
        'matter_id',
        'document_id',
        'reason',
        'status',
        'placed_by_type',
        'placed_by_id',
        'placed_at',
        'released_by_type',
        'released_by_id',
        'released_at',
        'release_reason',
    ];

    protected function casts(): array
    {
        return [
            'scope_type' => LegalHoldScope::class,
            'status' => LegalHoldStatus::class,
            'placed_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function isActive(): bool
    {
        return $this->status === LegalHoldStatus::Active;
    }
}
