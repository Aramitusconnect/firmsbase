<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PaymentPlan;
use App\Models\PaymentPlanInstallment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Covers the 4 Phase 3 models carrying a public UUIDv7 identifier via
 * App\Models\Concerns\HasPublicUuid: Invoice, PaymentPlan,
 * PaymentPlanInstallment, Payment. TimeEntry, TimeTrackingSession,
 * EmployeeRate (internal-only), InvoiceLine, ManualPaymentRecord
 * (accessed only through their parent), and PaymentPlanEvent /
 * PaymentClassificationEvent (internal audit logs) deliberately do NOT
 * carry a uuid in Phase 3.
 *
 * Uses PHPUnit attribute-based data providers (#[DataProvider]) only —
 * never the legacy `@dataProvider` docblock syntax.
 */
class Phase3PublicUuidTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array<int, class-string>>
     */
    public static function modelProvider(): array
    {
        return [
            'Invoice' => [Invoice::class],
            'PaymentPlan' => [PaymentPlan::class],
            'PaymentPlanInstallment' => [PaymentPlanInstallment::class],
            'Payment' => [Payment::class],
        ];
    }

    #[DataProvider('modelProvider')]
    public function test_model_receives_a_uuid_on_creation(string $modelClass): void
    {
        $model = $modelClass::factory()->create();

        $this->assertNotNull($model->uuid);
        $this->assertNotEmpty($model->uuid);
    }

    #[DataProvider('modelProvider')]
    public function test_uuid_is_a_valid_uuidv7(string $modelClass): void
    {
        $model = $modelClass::factory()->create();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $model->uuid,
            "{$modelClass}::uuid must be a version-7 UUID"
        );
    }

    #[DataProvider('modelProvider')]
    public function test_uuid_is_immutable_after_creation(string $modelClass): void
    {
        $model = $modelClass::factory()->create();

        // HasPublicUuid's saving() guard throws before the update is
        // ever attempted — matches Tests\Feature\Activation\
        // ActivationPublicUuidTest and Tests\Feature\Phase2PublicUuidTest.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('uuid is immutable');

        $model->uuid = (string) \Illuminate\Support\Str::uuid7();
        $model->save();
    }

    #[DataProvider('modelProvider')]
    public function test_route_key_name_is_uuid(string $modelClass): void
    {
        $model = $modelClass::factory()->create();

        $this->assertSame('uuid', $model->getRouteKeyName());
    }

    #[DataProvider('modelProvider')]
    public function test_uuid_column_is_unique(string $modelClass): void
    {
        $first = $modelClass::factory()->create();

        $this->expectException(\Illuminate\Database\QueryException::class);

        $modelClass::factory()->create(['uuid' => $first->uuid]);
    }
}
