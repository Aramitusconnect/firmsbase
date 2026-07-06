<?php

namespace App\Models;

use App\Enums\AiRetrievalIndexStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AiRetrievalIndex — records the structurally isolated namespace
 * provisioned for one firm (project rules 13/14). One row per firm
 * (unique firm_id). namespace_identifier is globally unique — no two
 * firms are ever assigned the same identifier, which is the
 * structural guarantee this table exists to prove, even though no real
 * retrieval backend is provisioned in Phase 15.
 */
class AiRetrievalIndex extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $table = 'ai_retrieval_indexes';

    protected $fillable = [
        'firm_id',
        'namespace_identifier',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'status' => AiRetrievalIndexStatus::class,
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function isProvisioned(): bool
    {
        return $this->status === AiRetrievalIndexStatus::Provisioned;
    }
}
