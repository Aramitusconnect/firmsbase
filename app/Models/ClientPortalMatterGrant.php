<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ClientPortalMatterGrant — Checkpoint 4 ("Plaid financial evidence
 * add-on"), Client Portal authentication foundation
 * (checkpoint4-design-matter-and-client-portal.md §2.6.3). An EXPLICIT
 * grant that a given `Client` (via their `ClientPortalUser` credential)
 * may see a given `Matter` inside the Client Portal — never an inferred
 * "any matter where matters.client_id = this client" rule (see the
 * create-table migration's own docblock for why explicit grants are
 * required, not merely convenient).
 *
 * `revoked_at` (rather than row deletion) preserves grant history,
 * matching `MatterAssignment.removed_at`'s established convention.
 * Direct `BelongsToTenant` + FORCE RLS — this table has its own direct,
 * NOT NULL `firm_id` column.
 */
class ClientPortalMatterGrant extends Model
{
    use BelongsToTenant, HasFactory, HasPublicUuid;

    protected $table = 'client_portal_matter_grants';

    protected $fillable = [
        'firm_id',
        'client_id',
        'matter_id',
        'granted_by',
        'granted_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
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

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function isActive(): bool
    {
        return is_null($this->revoked_at);
    }
}
