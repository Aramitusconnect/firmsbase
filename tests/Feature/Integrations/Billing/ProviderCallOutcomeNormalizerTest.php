<?php

declare(strict_types=1);

namespace Tests\Feature\Integrations\Billing;

use App\Integrations\Billing\ProviderCallOutcomeNormalizer;
use App\Integrations\Exceptions\SanitizedProviderHttpException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ProviderCallOutcomeNormalizerTest — checkpoint4-design-cost-control.md
 * §2 step 14 / §3.2. Proves the closed
 * SanitizedProviderHttpException::category() -> {billable, certain}
 * mapping: a success (no exception) is certain+billable; every
 * "provider rejected before doing the work" category is
 * certain+non-billable; and — the spec's own explicitly-named
 * "uncertain billing outcome" — network_error/timeout/unknown/
 * connection_unavailable/malformed_response are NEVER assumed billable
 * or non-billable, only `uncertain` (certain=false).
 */
class ProviderCallOutcomeNormalizerTest extends TestCase
{
    use RefreshDatabase;

    private ProviderCallOutcomeNormalizer $normalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->normalizer = new ProviderCallOutcomeNormalizer();
    }

    public function test_a_successful_response_with_no_exception_is_certain_and_billable(): void
    {
        $outcome = $this->normalizer->normalize(['ok' => true], null);

        $this->assertTrue($outcome->certain);
        $this->assertTrue($outcome->billable);
        $this->assertSame('success', $outcome->category);
    }

    #[DataProvider('uncertainCategoryProvider')]
    public function test_ambiguous_categories_produce_an_uncertain_outcome_never_billable_or_confidently_non_billable(string $category): void
    {
        $exception = new SanitizedProviderHttpException($category, null, 'fetchBalance');

        $outcome = $this->normalizer->normalize(null, $exception);

        $this->assertFalse($outcome->certain, "Expected category [{$category}] to be uncertain.");
        $this->assertFalse($outcome->billable, "An uncertain outcome must never report billable=true for [{$category}].");
        $this->assertSame($category, $outcome->category);
    }

    public static function uncertainCategoryProvider(): array
    {
        return [
            [SanitizedProviderHttpException::CATEGORY_NETWORK_ERROR],
            [SanitizedProviderHttpException::CATEGORY_TIMEOUT],
            [SanitizedProviderHttpException::CATEGORY_UNKNOWN],
            [SanitizedProviderHttpException::CATEGORY_CONNECTION_UNAVAILABLE],
            [SanitizedProviderHttpException::CATEGORY_MALFORMED_RESPONSE],
        ];
    }

    #[DataProvider('nonBillableCategoryProvider')]
    public function test_rejected_before_processing_categories_are_certain_and_non_billable(string $category): void
    {
        $exception = new SanitizedProviderHttpException($category, null, 'fetchBalance');

        $outcome = $this->normalizer->normalize(null, $exception);

        $this->assertTrue($outcome->certain, "Expected category [{$category}] to be certain.");
        $this->assertFalse($outcome->billable);
        $this->assertSame($category, $outcome->category);
    }

    public static function nonBillableCategoryProvider(): array
    {
        return [
            [SanitizedProviderHttpException::CATEGORY_AUTHENTICATION_FAILED],
            [SanitizedProviderHttpException::CATEGORY_AUTHORIZATION_FAILED],
            [SanitizedProviderHttpException::CATEGORY_VALIDATION_FAILED],
            [SanitizedProviderHttpException::CATEGORY_PROVIDER_REJECTED],
            [SanitizedProviderHttpException::CATEGORY_CONFLICT],
            [SanitizedProviderHttpException::CATEGORY_RATE_LIMITED],
            [SanitizedProviderHttpException::CATEGORY_INVALID_GRANT],
            [SanitizedProviderHttpException::CATEGORY_CONFIGURATION_ERROR],
            [SanitizedProviderHttpException::CATEGORY_CURSOR_EXPIRED],
        ];
    }

    public function test_uncertain_and_non_billable_outcomes_are_mutually_exclusive_partitions_of_every_closed_category(): void
    {
        $allCategories = array_merge(
            array_column(self::uncertainCategoryProvider(), 0),
            array_column(self::nonBillableCategoryProvider(), 0),
        );

        // Every category this exception type can carry must be
        // classified as exactly one of uncertain/non-billable — never
        // both, never neither, and success is the only certain+billable
        // path (proven separately above).
        sort($allCategories);
        $reflection = new \ReflectionClass(SanitizedProviderHttpException::class);
        $constants = array_values(array_filter(
            $reflection->getConstants(),
            fn ($value, $name) => str_starts_with($name, 'CATEGORY_'),
            ARRAY_FILTER_USE_BOTH,
        ));
        sort($constants);

        $this->assertSame($constants, $allCategories);
    }
}
