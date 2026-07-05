<?php

namespace App\Models;

use App\Enums\EmailVisibilityScope;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * EmailVisibilityRule — a small, fixed-scope policy row, not a
 * generic per-user ACL/grant system (project rule). matter_id null =
 * the account-level default rule; matter_id set = a matter-specific
 * override. No uuid — resolved internally by EmailVisibilityPolicyService,
 * never referenced externally.
 */
class EmailVisibilityRule extends Model
{
    use HasFactory, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'email_account_id',
        'matter_id',
        'visibility_scope',
        'created_by_firm_user_id',
    ];

    protected function casts(): array
    {
        return [
            'visibility_scope' => EmailVisibilityScope::class,
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function emailAccount(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class);
    }

    public function matter(): BelongsTo
    {
        return $this->belongsTo(Matter::class);
    }

    public function createdByFirmUser(): BelongsTo
    {
        return $this->belongsTo(FirmUser::class, 'created_by_firm_user_id');
    }
}
