<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Contact — client_id is nullable: a contact can exist independent of
 * any client. encrypted_sensitive_fields is reserved storage; Phase 2
 * does not wire it to Phase 1's EncryptionKeyService (approved
 * decision) — it is deliberately NOT cast to 'encrypted' here, since
 * nothing populates it yet and casting an always-null column adds no
 * value. A later phase that wires this up should add the cast then.
 */
class Contact extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $fillable = [
        'firm_id',
        'client_id',
        'name',
        'company',
        'email',
        'phone',
        'role',
        'normalized_search_keys',
        'encrypted_sensitive_fields',
    ];

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
