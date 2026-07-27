<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Checkpoint 2 (FirmsVault Live Integrations, Microsoft 365 provider —
 * checkpoint2-design-oauth-capabilities.md §1.3; checkpoint2-combined-design.md
 * §1) addition: seeds the `integration_providers` catalog row for
 * `microsoft365`. Exact template: `2026_09_01_010001_create_integration_providers_table.php`'s
 * own `DB::table('integration_providers')->insert([...])` block (the
 * `test` row) — a new, dedicated, additive-only migration, matching
 * `2026_09_08_082001_seed_integration_module_catalog_entry.php`'s
 * established "one small seed-only migration per catalog addition"
 * naming/isolation convention, rather than editing the original
 * `create_integration_providers_table` migration.
 *
 * `status => 'active'` is set immediately (mirrors `test`'s row) — the
 * REAL gate on whether a firm can actually connect is
 * `config('integrations.providers')`'s env-conditional class map entry
 * (`ConnectProviderAction`'s dropdown filters on BOTH
 * `IntegrationProvider::status = 'active'` AND
 * `ProviderRegistry::has(ProviderKey::tryFrom($provider->code))`) — the
 * same two-layer gate pattern already proven for `test`. This lets the
 * catalog row (and therefore PlatformProviderHealthPage,
 * WebhookEventResource's filter dropdown, etc.) exist and be visible to
 * PlatformAdmin tooling before INTEGRATIONS_MICROSOFT365_ENABLED is
 * ever flipped on for firms, and before Microsoft365Provider itself is
 * built.
 *
 * `required_oauth_scopes_json`/`webhook_event_types_json` are,
 * per this table's own migration docblock, presentation/documentation-
 * only — never consulted by ProviderRegistry or any capability-
 * resolution code path. The real, authoritative, executable scope
 * logic lives entirely in `Microsoft365Provider::requiredScopes()`/
 * `capabilityScopeMap()` (a later checkpoint's file), not here.
 * `webhook_event_types_json` is left empty — populated by whichever
 * checkpoint/agent owns webhook-adapter design.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('integration_providers')->insert([
            'code' => 'microsoft365',
            'display_name' => 'Microsoft 365',
            'category' => 'productivity',
            'auth_method' => 'oauth2',
            'status' => 'active',
            'module_code' => null,
            'degradation_type_key' => null,
            'required_oauth_scopes_json' => json_encode([
                'offline_access', 'openid', 'profile',
                'Mail.Read', 'Mail.Send',
                'Calendars.Read', 'Calendars.ReadWrite',
                'Files.Read', 'Files.ReadWrite',
                'Contacts.Read',
            ]),
            'webhook_event_types_json' => json_encode([]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('integration_providers')->where('code', 'microsoft365')->delete();
    }
};
