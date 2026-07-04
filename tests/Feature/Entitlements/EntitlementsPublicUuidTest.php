<?php

namespace Tests\Feature\Entitlements;

use App\Models\FirmEntitlement;
use App\Models\FirmLicense;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ModuleCatalog and FirmEntitlementEvent are intentionally excluded —
 * neither has a uuid column. Uses PHPUnit attribute-based data
 * providers (#[DataProvider(...)]), not the legacy @dataProvider
 * docblock annotation, which throws ArgumentCountError under the
 * PHPUnit version paired with Laravel 13.
 */
class EntitlementsPublicUuidTest extends TestCase
{
    use RefreshDatabase;

    public static function modelProvider(): array
    {
        return [
            'FirmLicense' => [FirmLicense::class],
            'FirmEntitlement' => [FirmEntitlement::class],
        ];
    }

    #[DataProvider('modelProvider')]
    public function test_uuid_is_present_unique_and_version_7(string $modelClass): void
    {
        $a = $modelClass::factory()->create();
        $b = $modelClass::factory()->create();

        $this->assertNotEmpty($a->uuid);
        $this->assertNotSame($a->uuid, $b->uuid);

        foreach ([$a->uuid, $b->uuid] as $uuid) {
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                $uuid
            );
        }
    }

    #[DataProvider('modelProvider')]
    public function test_uuid_is_immutable_after_creation(string $modelClass): void
    {
        $model = $modelClass::factory()->create();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('uuid is immutable');

        $model->uuid = (string) \Illuminate\Support\Str::uuid7();
        $model->save();
    }
}
