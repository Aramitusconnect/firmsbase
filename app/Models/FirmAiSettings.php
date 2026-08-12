<?php

namespace App\Models;

use App\Enums\AiProvider;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasPublicUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * FirmAiSettings — one row per firm, the detailed AI controls table
 * (approved decision #2). Does not carry ai_mode — see
 * firm_settings.ai_mode (Phase 1) for the single source of truth on
 * mode.
 */
class FirmAiSettings extends Model
{
    use BelongsToTenant, HasFactory, HasPublicUuid;

    protected $table = 'firm_ai_settings';

    protected $fillable = [
        'firm_id',
        'allowed_providers_json',
        'allowed_models_json',
        'token_limit_per_period',
        'budget_limit_cents_per_period',
        'usage_markup_basis_points',
        'document_context_enabled',
        'client_data_context_enabled',
        'high_risk_requires_approval',
        'full_content_logging_enabled',
        'intake_ai_assist_enabled',
    ];

    protected $attributes = [
        'usage_markup_basis_points' => 0,
        'document_context_enabled' => false,
        'client_data_context_enabled' => false,
        'high_risk_requires_approval' => true,
        'full_content_logging_enabled' => false,
        'intake_ai_assist_enabled' => false,
    ];

    protected function casts(): array
    {
        return [
            'allowed_providers_json' => 'array',
            'allowed_models_json' => 'array',
            'token_limit_per_period' => 'integer',
            'budget_limit_cents_per_period' => 'integer',
            'usage_markup_basis_points' => 'integer',
            'document_context_enabled' => 'boolean',
            'client_data_context_enabled' => 'boolean',
            'high_risk_requires_approval' => 'boolean',
            'full_content_logging_enabled' => 'boolean',
            'intake_ai_assist_enabled' => 'boolean',
        ];
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function allowsProvider(AiProvider $provider): bool
    {
        if (empty($this->allowed_providers_json)) {
            return false;
        }

        return in_array($provider->value, $this->allowed_providers_json, true);
    }

    public function allowsModel(string $model): bool
    {
        if (empty($this->allowed_models_json)) {
            return false;
        }

        return in_array($model, $this->allowed_models_json, true);
    }
}
