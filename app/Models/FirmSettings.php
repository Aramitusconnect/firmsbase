<?php

namespace App\Models;

use App\Enums\AiMode;
use App\Enums\PaymentMode;
use App\Enums\TwoFactorMode;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FirmSettings — no stripe_enabled column (no payment processing yet).
 * branding_settings_json/security_settings_json default to '{}' at the
 * application layer (see $attributes below) rather than via a raw SQL
 * column default, for portability.
 *
 * Section 39B: firm_user_2fa_mode mirrors client_2fa_mode exactly
 * (same TwoFactorMode enum, same 'optional' default) — governs whether
 * FirmUser2faPolicyService requires every active firm user's
 * User.two_factor_confirmed_at to be set. Defaulting to 'optional'
 * means adding this column never locks out an existing dev/test user;
 * only a firm explicitly switched to 'required' is subject to the
 * compliance check.
 */
class FirmSettings extends Model
{
    use HasFactory, HasPublicUuid, BelongsToTenant;

    protected $table = 'firm_settings';

    protected $fillable = [
        'firm_id',
        'payment_mode',
        'trust_iolta_protection',
        'ai_mode',
        'client_2fa_mode',
        'firm_user_2fa_mode',
        'portal_frontend_mode',
        'state_jurisdiction',
        'default_language',
        'branding_settings_json',
        'security_settings_json',
    ];

    protected $attributes = [
        'branding_settings_json' => '{}',
        'security_settings_json' => '{}',
    ];

    protected function casts(): array
    {
        return [
            'payment_mode' => PaymentMode::class,
            'trust_iolta_protection' => 'boolean',
            'ai_mode' => AiMode::class,
            'client_2fa_mode' => TwoFactorMode::class,
            'firm_user_2fa_mode' => TwoFactorMode::class,
            'branding_settings_json' => 'array',
            'security_settings_json' => 'array',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }
}
