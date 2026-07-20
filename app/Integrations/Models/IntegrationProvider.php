<?php

declare(strict_types=1);

namespace App\Integrations\Models;

use Database\Factories\IntegrationProviderFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * IntegrationProvider — Global/platform-wide reference data, never
 * firm-scoped (checkpoint-00-final-specification.md §5 table #1;
 * domain-model-and-rls-classification.md §1). Structurally mirrors
 * App\Models\ModuleCatalog's shape: a small, static, seeded-only
 * catalog with no BelongsToTenant/tenant trait and no RLS.
 *
 * Rows here are purely presentation/documentation-only metadata and
 * are looked up AGAINST the code-defined
 * App\Integrations\Core\ProviderRegistry (Checkpoint 1) — this table
 * never controls executable adapter behavior, capability resolution,
 * or class instantiation. `required_oauth_scopes_json` in particular
 * is documentation only and is never authoritative for runtime scope
 * enforcement.
 */
class IntegrationProvider extends Model
{
    use HasFactory;

    protected $table = 'integration_providers';

    protected $fillable = [
        'code',
        'display_name',
        'category',
        'auth_method',
        'status',
        'module_code',
        'degradation_type_key',
        'required_oauth_scopes_json',
        'webhook_event_types_json',
    ];

    protected function casts(): array
    {
        return [
            'required_oauth_scopes_json' => 'array',
            'webhook_event_types_json' => 'array',
        ];
    }

    protected static function newFactory(): IntegrationProviderFactory
    {
        return IntegrationProviderFactory::new();
    }
}
