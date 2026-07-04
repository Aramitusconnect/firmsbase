<?php

namespace App\Models;

use App\Enums\FirmUserRole;
use App\Enums\FirmUserStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FirmUser extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $table = 'firm_users';

    protected $fillable = [
        'user_id',
        'firm_id',
        'role',
        'status',
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
}
