<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Ui;

use App\Filament\Firm\Resources\FirmIntegrationResource\Actions\ConnectProviderAction;
use App\Integrations\Enums\ProviderKey;
use App\Integrations\Models\IntegrationProvider;
use App\Integrations\Providers\TestProvider\TestProvider;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionProperty;
use Tests\TestCase;

/**
 * FirmIntegrationConnectProviderDropdownVisibilityTest — Checkpoint 12
 * (frozen-design-post-security-review.md §2 F1, §3, §8). Proves the
 * confirmed §18 violation (12H verification item 5: "no
 * ProviderRegistry/isConfigured() reference anywhere in the file") is
 * genuinely closed: with the TestProvider environment flag OFF, the
 * migration-seeded `integration_providers` row (code='test', status=
 * 'active' — see
 * database/migrations/2026_09_01_010001_create_integration_providers_table.php)
 * must be excluded from ConnectProviderAction's dropdown options even
 * though the catalog row itself is Active; with the flag ON, it must be
 * included. Also proves the orphaned-catalog-row edge case: a row whose
 * `code` does not map to any ProviderKey case is gracefully filtered
 * out, never a fatal error (ProviderKey::tryFrom() returning null,
 * short-circuiting the ->filter() closure's registry->has() call).
 *
 * Exercises the REAL Select::options() closure directly (not a
 * simulated/duplicated copy of ConnectProviderAction's filtering logic)
 * — ConnectProviderAction::make() runs the real setUp() that wires the
 * closure, and Filament's own EvaluatesClosures::evaluate() resolves the
 * closure's typed ProviderRegistry parameter via the real application
 * container, exactly as it would during an actual page render. This is
 * a genuine exercise of the production closure, not a rewritten
 * assertion of what it OUGHT to do.
 */
final class FirmIntegrationConnectProviderDropdownVisibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        TestProvider::resetSimulationState();
        parent::tearDown();
    }

    public function test_the_test_provider_option_is_excluded_when_the_flag_is_off(): void
    {
        // The migration-seeded row already exists (status='active',
        // code='test') — deliberately does NOT re-create or override it,
        // proving the real seeded catalog row is what gets excluded.
        config(['integrations.providers' => [ProviderKey::Test->value => null]]);

        $options = $this->dropdownOptions();

        $this->assertNotContains(
            'Internal Test Provider (non-production)',
            $options,
            'With the environment flag OFF, ProviderRegistry->has(ProviderKey::Test) must be false, and the catalog row must be filtered out of the dropdown entirely — never merely disabled or greyed out.'
        );
    }

    public function test_the_test_provider_option_is_included_when_the_flag_is_on(): void
    {
        config(['integrations.providers' => [ProviderKey::Test->value => TestProvider::class]]);

        $options = $this->dropdownOptions();

        $this->assertContains(
            'Internal Test Provider (non-production)',
            $options,
            'With the environment flag ON, the real seeded, active TestProvider catalog row must be offered in the dropdown.'
        );
    }

    public function test_an_inactive_catalog_row_is_excluded_even_when_its_provider_key_is_registered(): void
    {
        config(['integrations.providers' => [ProviderKey::Test->value => TestProvider::class]]);

        IntegrationProvider::query()->where('code', ProviderKey::Test->value)->update(['status' => 'inactive']);

        $options = $this->dropdownOptions();

        $this->assertNotContains(
            'Internal Test Provider (non-production)',
            $options,
            'A catalog row with status != active must never appear, regardless of registry resolvability.'
        );
    }

    public function test_an_orphaned_catalog_row_whose_code_matches_no_provider_key_is_gracefully_excluded_not_a_fatal_error(): void
    {
        config(['integrations.providers' => [ProviderKey::Test->value => TestProvider::class]]);

        IntegrationProvider::factory()->create([
            'code' => 'a-code-with-no-matching-provider-key-case',
            'display_name' => 'Orphaned Catalog Row',
            'status' => 'active',
        ]);

        // Must not throw (ProviderKey::tryFrom() returning null for the
        // orphaned row's code, rather than ProviderKey::from() which
        // would throw a ValueError) and must not appear in the options.
        $options = $this->dropdownOptions();

        $this->assertNotContains('Orphaned Catalog Row', $options);
        // The genuine, resolvable TestProvider row must still be
        // present alongside the gracefully-skipped orphaned row — proves
        // the orphan doesn't abort the whole options() evaluation.
        $this->assertContains('Internal Test Provider (non-production)', $options);
    }

    public function test_an_active_catalog_row_whose_code_matches_no_provider_key_never_appears_alongside_a_disabled_test_provider_either(): void
    {
        config(['integrations.providers' => [ProviderKey::Test->value => null]]);

        IntegrationProvider::factory()->create([
            'code' => 'another-orphaned-code',
            'display_name' => 'Another Orphaned Row',
            'status' => 'active',
        ]);

        $options = $this->dropdownOptions();

        $this->assertSame([], $options, 'With TestProvider disabled and only an orphaned catalog row present, the dropdown must be genuinely empty, never fatally erroring and never falling back to showing the orphan.');
    }

    /**
     * @return array<int, string> the option LABELS (display_name values)
     *                            currently produced by ConnectProviderAction's
     *                            real Select::options() closure.
     */
    private function dropdownOptions(): array
    {
        $action = ConnectProviderAction::make(ConnectProviderAction::getDefaultName());

        $schemaProperty = new ReflectionProperty($action, 'schema');
        $schemaProperty->setAccessible(true);
        $components = $schemaProperty->getValue($action);

        $select = collect($components)->first(fn ($component): bool => $component instanceof Select && $component->getName() === 'integration_provider_id');

        $this->assertInstanceOf(Select::class, $select, 'Sanity check: ConnectProviderAction must still define the integration_provider_id Select.');

        return array_values($select->getOptions());
    }
}
