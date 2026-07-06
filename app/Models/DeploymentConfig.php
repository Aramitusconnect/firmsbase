<?php

namespace App\Models;

use App\Enums\BootCheckStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DeploymentConfig — one row per firm operating in dedicated/private
 * mode. isolated_database/isolated_storage are declarations only — no
 * real provisioning. The four trust_iolta_disabled_* fields are the
 * firm-side acknowledgment half of approved decision #2; the
 * platform-admin-approval half lives in high_risk_change_requests via
 * TrustIoltaDisableAcknowledgmentService.
 */
class DeploymentConfig extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $table = 'deployment_configs';

    protected $fillable = [
        'firm_id',
        'custom_domain',
        'isolated_database',
        'isolated_storage',
        'custom_retention_policy_json',
        'custom_support_access_json',
        'custom_compliance_settings_json',
        'boot_check_status',
        'trust_iolta_disabled_acknowledged_at',
        'trust_iolta_disabled_acknowledged_by',
        'trust_iolta_disabled_acknowledgment_text',
        'trust_iolta_disabled_acknowledgment_version',
    ];

    protected $attributes = [
        'isolated_database' => false,
        'isolated_storage' => false,
        'boot_check_status' => 'not_yet_run',
    ];

    protected function casts(): array
    {
        return [
            'isolated_database' => 'boolean',
            'isolated_storage' => 'boolean',
            'custom_retention_policy_json' => 'array',
            'custom_support_access_json' => 'array',
            'custom_compliance_settings_json' => 'array',
            'boot_check_status' => BootCheckStatus::class,
            'trust_iolta_disabled_acknowledged_at' => 'datetime',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function trustIoltaDisabledAcknowledgedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'trust_iolta_disabled_acknowledged_by');
    }

    public function hasFirmAcknowledgedTrustIoltaDisabled(): bool
    {
        return $this->trust_iolta_disabled_acknowledged_at !== null
            && $this->trust_iolta_disabled_acknowledged_by !== null
            && ! empty($this->trust_iolta_disabled_acknowledgment_text)
            && ! empty($this->trust_iolta_disabled_acknowledgment_version);
    }
}
