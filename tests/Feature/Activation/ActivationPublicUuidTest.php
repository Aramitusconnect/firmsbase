<?php

namespace Tests\Feature\Activation;

use App\Models\ActivationChecklist;
use App\Models\ClientCommunicationPreference;
use App\Models\CommunicationConsent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * TenantEncryptionKey (internal key material), ActivationChecklistItem,
 * and CommunicationConsentEvent (append-only log) are intentionally
 * excluded — none has a uuid column. Uses PHPUnit attribute-based data
 * providers, not the legacy @dataProvider docblock annotation.
 */
class ActivationPublicUuidTest extends TestCase
{
    use RefreshDatabase;

    public static function modelProvider(): array
    {
        return [
            'ActivationChecklist' => [ActivationChecklist::class],
            'ClientCommunicationPreference' => [ClientCommunicationPreference::class],
            'CommunicationConsent' => [CommunicationConsent::class],
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
