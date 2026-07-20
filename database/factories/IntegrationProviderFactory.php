<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Integrations\Models\IntegrationProvider;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IntegrationProvider>
 *
 * IntegrationProvider is Global/platform-wide reference data (no
 * firm_id, no tenant context) — this factory mirrors ModuleCatalogFactory's
 * shape: no context-hold/create() override is needed because there is no
 * firm dimension to reconcile.
 *
 * `code` is always a synthetic, obviously-fake, unique value
 * (`test-fixture-<random>`) — deliberately NEVER 'test' (the one real
 * seeded row, App\Integrations\Enums\ProviderKey::Test->value) and
 * NEVER any real provider key (google/microsoft/stripe/quickbooks/
 * lawpay/clio/plaid/zoom/dropbox/etc.), so factory-generated rows can
 * never collide with, or be mistaken for, seeded/production catalog
 * data. This table's schema carries no secret/credential-shaped field
 * at all (only presentation/documentation metadata), so there is
 * nothing sensitive to fake here.
 */
class IntegrationProviderFactory extends Factory
{
    protected $model = IntegrationProvider::class;

    public function definition(): array
    {
        return [
            'code' => 'test-fixture-'.fake()->unique()->regexify('[a-z0-9]{12}'),
            'display_name' => 'Fixture Provider '.fake()->words(2, true),
            'category' => 'fixture',
            'auth_method' => 'oauth2',
            'status' => 'active',
            'module_code' => null,
            'degradation_type_key' => null,
            'required_oauth_scopes_json' => [],
            'webhook_event_types_json' => [],
        ];
    }
}
