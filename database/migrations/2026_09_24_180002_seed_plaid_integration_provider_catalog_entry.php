<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * FirmsVault Live Integrations, Checkpoint 4 ("Plaid financial evidence
 * add-on" — checkpoint4-design-plaid-provider-core.md §1;
 * checkpoint4-combined-design.md §1.5/§6.1) addition: seeds the
 * `integration_providers` catalog row for `plaid`. Exact template:
 * `2026_09_23_170002_seed_googleworkspace_integration_provider_catalog_entry.php`'s
 * own `DB::table('integration_providers')->insert([...])` block — a
 * new, dedicated, additive-only migration, matching this mission's
 * established "one small seed-only migration per catalog addition"
 * naming/isolation convention.
 *
 * Distinct from, and never to be confused with, the SEPARATE
 * `..._seed_plaid_module_catalog_entry.php` migration (owned by the
 * cost-control/billing track, not this file) — that migration seeds a
 * row in the `plan_modules`-adjacent module/entitlement catalog
 * (`module_code => 'plaid'`, gating the paid Plaid add-on entitlement
 * via `PlaidEntitlementPolicyService`). This migration seeds only the
 * `integration_providers` catalog row (`code => 'plaid'`) — the same
 * catalog Microsoft 365/Google Workspace's own seed migrations
 * populate. Both are required, both are new, and both are genuinely
 * distinct rows in genuinely distinct tables
 * (checkpoint4-combined-design.md §1.5's disambiguation).
 *
 * `code => 'plaid'` — the reconciled, binding value confirmed
 * identically across all four Checkpoint 4 source docs
 * (checkpoint4-combined-design.md §1.2): lowercase, zero separator, an
 * exact structural match to `'microsoft365'`/`'googleworkspace'`'s own
 * shape.
 *
 * `auth_method => 'link_token'` — matches the new
 * `App\Integrations\Enums\AuthMethod::LinkToken` case's own string
 * value; Plaid's auth model is neither `oauth2` nor `api_key`.
 *
 * `status => 'active'` is set immediately (mirrors `microsoft365`/
 * `googleworkspace`'s own rows) — the REAL gate on whether a firm can
 * actually connect is `config('integrations.providers')`'s
 * env-conditional class map entry
 * (`INTEGRATIONS_PLAID_ENABLED`), the same two-layer gate pattern
 * already proven for every other real provider in this mission.
 *
 * `required_oauth_scopes_json`/`webhook_event_types_json` are, per this
 * table's own migration docblock, presentation/documentation-only —
 * never consulted by ProviderRegistry or any capability-resolution code
 * path. Plaid has no OAuth "scope" concept at all (a successfully
 * exchanged public_token IS full consent for whichever `products` were
 * requested at `createLinkToken()` time — see
 * `PlaidProvider::exchangePublicToken()`'s own docblock), so
 * `required_oauth_scopes_json` is left empty here; `webhook_event_types_json`
 * mirrors `PlaidProvider::webhookEventTypes()`'s own closed vocabulary
 * for documentation purposes.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('integration_providers')->insert([
            'code' => 'plaid',
            'display_name' => 'Plaid',
            'category' => 'financial',
            'auth_method' => 'link_token',
            'status' => 'active',
            'module_code' => null,
            'degradation_type_key' => null,
            'required_oauth_scopes_json' => json_encode([]),
            'webhook_event_types_json' => json_encode([
                'transaction:sync_updates_available',
                'transaction:recurring_transactions_update',
                'lifecycle:item_error',
                'lifecycle:item_login_repaired',
            ]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('integration_providers')->where('code', 'plaid')->delete();
    }
};
