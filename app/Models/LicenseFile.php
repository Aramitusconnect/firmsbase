<?php

namespace App\Models;

use App\Enums\DeploymentMode;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * LicenseFile — the signed offline license artifact record. Supports
 * both a firm-level artifact (firm_id + firm_license_id) and an
 * organization-level artifact (organization_id + org_license_id)
 * (approved decision #4) — a database CHECK constraint enforces
 * exactly one owner path; isFirmLevel()/isOrganizationLevel() below
 * are read-only convenience mirrors of that same constraint, never a
 * substitute for it. No BelongsToTenant: an organization-level row has
 * no firm_id at all, so a firm-scoped global scope cannot safely apply
 * uniformly across this table.
 */
class LicenseFile extends Model
{
    use HasFactory, \App\Models\Concerns\HasPublicUuid;

    protected $table = 'license_files';

    protected $fillable = [
        'firm_id',
        'organization_id',
        'firm_license_id',
        'org_license_id',
        'licensed_to',
        'license_key',
        'signed_payload',
        'signature',
        'signature_algorithm',
        'deployment_mode',
        'expires_at',
        'grace_period_days',
        'issued_at',
        'issued_by',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'deployment_mode' => DeploymentMode::class,
            'expires_at' => 'datetime',
            'grace_period_days' => 'integer',
            'issued_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function firmLicense(): BelongsTo
    {
        return $this->belongsTo(FirmLicense::class);
    }

    public function orgLicense(): BelongsTo
    {
        return $this->belongsTo(OrgLicense::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function validationEvents(): HasMany
    {
        return $this->hasMany(LicenseValidationEvent::class);
    }

    public function isFirmLevel(): bool
    {
        return $this->firm_id !== null && $this->firm_license_id !== null;
    }

    public function isOrganizationLevel(): bool
    {
        return $this->organization_id !== null && $this->org_license_id !== null;
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }
}
