<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ConflictCheckRun;
use App\Models\Consultation;
use App\Models\Contact;
use App\Models\FirmLead;
use App\Models\IntakeSubmission;
use App\Models\Matter;
use App\Models\Party;
use App\Models\TimelineEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class Phase2PublicUuidTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array<int, class-string>>
     */
    public static function modelProvider(): array
    {
        return [
            'FirmLead' => [FirmLead::class],
            'Consultation' => [Consultation::class],
            'Client' => [Client::class],
            'Contact' => [Contact::class],
            'Party' => [Party::class],
            'Matter' => [Matter::class],
            'IntakeSubmission' => [IntakeSubmission::class],
            'ConflictCheckRun' => [ConflictCheckRun::class],
            'TimelineEvent' => [TimelineEvent::class],
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

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('uuid is immutable');

        $model->uuid = '018f1f77-1111-7111-8111-111111111111';
        $model->save();
    }
}
