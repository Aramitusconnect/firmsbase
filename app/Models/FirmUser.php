<?php

namespace App\Models;

use App\Enums\FirmUserRole;
use App\Enums\FirmUserStatus;
use App\Enums\SeatClass;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 6 addition: seat_class (nullable). FirmUserRole itself is
 * UNTOUCHED (approved decision — no read_only role was added to it).
 * effectiveSeatClass() is the ONLY place the default-class derivation
 * table is implemented; SeatEnforcementService and every other Phase 6
 * caller must go through this method rather than re-deriving the
 * mapping themselves, so the rule lives in exactly one place:
 *   - explicit seat_class column value always wins, including
 *     read_only (which has no role that implies it — it can only ever
 *     be reached by an explicit assignment).
 *   - null falls back to: FirmOwner/Attorney -> attorney;
 *     Paralegal/LegalAssistant/Receptionist/BillingStaff -> staff.
 * Client portal users are never firm_users rows at all (Client is a
 * distinct model), so they are automatically excluded from every seat
 * computation built on this method without any special-case code.
 */
class FirmUser extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $table = 'firm_users';

    protected $fillable = [
        'user_id',
        'firm_id',
        'role',
        'status',
        'seat_class',
        'is_primary',
        'invited_by',
        'invitation_token',
        'invitation_accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'role' => FirmUserRole::class,
            'status' => FirmUserStatus::class,
            'seat_class' => SeatClass::class,
            'is_primary' => 'boolean',
            'invitation_accepted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * Phase 6 addition: the approved default-derivation table. An
     * explicit seat_class always wins; read_only can only be reached
     * this way, never as a default.
     */
    public function effectiveSeatClass(): SeatClass
    {
        if ($this->seat_class !== null) {
            return $this->seat_class;
        }

        return match ($this->role) {
            FirmUserRole::FirmOwner, FirmUserRole::Attorney => SeatClass::Attorney,
            FirmUserRole::Paralegal, FirmUserRole::LegalAssistant,
            FirmUserRole::Receptionist, FirmUserRole::BillingStaff => SeatClass::Staff,
        };
    }
}
