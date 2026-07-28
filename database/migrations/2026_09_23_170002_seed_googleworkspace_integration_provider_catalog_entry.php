<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Checkpoint 3 (FirmsVault Live Integrations, Google Workspace provider —
 * checkpoint3-combined-design.md §1.1/§4.1) addition: seeds the
 * `integration_providers` catalog row for `googleworkspace`. Exact
 * template: `2026_09_21_150002_seed_microsoft365_integration_provider_catalog_entry.php`'s
 * own `DB::table('integration_providers')->insert([...])` block — a new,
 * dedicated, additive-only migration, matching this mission's established
 * "one small seed-only migration per catalog addition" naming/isolation
 * convention, rather than editing the original
 * `create_integration_providers_table` migration or Microsoft 365's own
 * seed migration.
 *
 * `code => 'googleworkspace'` (not `'google_workspace'`) — the
 * reconciled, binding value per checkpoint3-combined-design.md §1.1: a
 * full compound provider name, lowercase, zero separator, an exact
 * structural match to `'microsoft365'`'s own shape.
 *
 * `status => 'active'` is set immediately (mirrors `microsoft365`'s own
 * row) — the REAL gate on whether a firm can actually connect is
 * `config('integrations.providers')`'s env-conditional class map entry
 * (`ConnectProviderAction`'s dropdown filters on BOTH
 * `IntegrationProvider::status = 'active'` AND
 * `ProviderRegistry::has(ProviderKey::tryFrom($provider->code))`) — the
 * same two-layer gate pattern already proven for `test` and
 * `microsoft365`. This lets the catalog row exist and be visible to
 * PlatformAdmin tooling before INTEGRATIONS_GOOGLEWORKSPACE_ENABLED is
 * ever flipped on for firms, and independent of whether
 * GoogleWorkspaceProvider itself has finished being built in a parallel
 * change within this same checkpoint.
 *
 * `required_oauth_scopes_json`/`webhook_event_types_json` are, per this
 * table's own migration docblock, presentation/documentation-only —
 * never consulted by ProviderRegistry or any capability-resolution code
 * path. The real, authoritative, executable scope logic lives entirely
 * in `GoogleWorkspaceProvider::requiredScopes()`/`capabilityScopeMap()`
 * (checkpoint3-combined-design.md §4.2's binding scope-bundle table),
 * not here. `webhook_event_types_json` is left empty, matching
 * Microsoft 365's own precedent.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('integration_providers')->insert([
            'code' => 'googleworkspace',
            'display_name' => 'Google Workspace',
            'category' => 'productivity',
            'auth_method' => 'oauth2',
            'status' => 'active',
            'module_code' => null,
            'degradation_type_key' => null,
            'required_oauth_scopes_json' => json_encode([
                'openid', 'email',
                'https://www.googleapis.com/auth/gmail.readonly',
                'https://www.googleapis.com/auth/gmail.send',
                'https://www.googleapis.com/auth/calendar.events',
                'https://www.googleapis.com/auth/drive.file',
            ]),
            'webhook_event_types_json' => json_encode([]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('integration_providers')->where('code', 'googleworkspace')->delete();
    }
};
