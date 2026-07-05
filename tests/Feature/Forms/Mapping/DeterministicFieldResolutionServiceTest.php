<?php

namespace Tests\Feature\Forms\Mapping;

use App\Enums\FormMappingSourceEntity;
use App\Enums\FormMappingTransform;
use App\Models\Client;
use App\Models\Matter;
use App\Services\DeterministicFieldResolutionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeterministicFieldResolutionServiceTest extends TestCase
{
    use RefreshDatabase;

    private DeterministicFieldResolutionService $resolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resolver = new DeterministicFieldResolutionService();
    }

    public function test_resolves_an_allowlisted_client_path(): void
    {
        $client = Client::factory()->create(['display_name' => 'Jane Doe']);

        $value = $this->resolver->resolve(FormMappingSourceEntity::Client, 'client.display_name', ['client' => $client]);

        $this->assertSame('Jane Doe', $value);
    }

    public function test_returns_null_for_a_path_not_on_the_allowlist(): void
    {
        $client = Client::factory()->create();

        $value = $this->resolver->resolve(FormMappingSourceEntity::Client, 'client.ssn', ['client' => $client]);

        $this->assertNull($value);
    }

    public function test_returns_null_when_context_entity_is_missing(): void
    {
        $value = $this->resolver->resolve(FormMappingSourceEntity::Client, 'client.display_name', []);

        $this->assertNull($value);
    }

    public function test_intake_submission_path_is_a_direct_single_level_json_key_lookup(): void
    {
        $intake = new \App\Models\IntakeSubmission(['responses_json' => ['country_of_birth' => 'Mexico']]);

        $value = $this->resolver->resolve(
            FormMappingSourceEntity::IntakeSubmission,
            'intake_submission.country_of_birth',
            ['intakeSubmission' => $intake]
        );

        $this->assertSame('Mexico', $value);
    }

    public function test_intake_submission_path_does_not_traverse_nested_keys(): void
    {
        $intake = new \App\Models\IntakeSubmission(['responses_json' => ['address' => ['city' => 'Miami']]]);

        $value = $this->resolver->resolve(
            FormMappingSourceEntity::IntakeSubmission,
            'intake_submission.address.city',
            ['intakeSubmission' => $intake]
        );

        $this->assertNull($value);
    }

    public function test_apply_transform_uppercase(): void
    {
        $this->assertSame('JANE DOE', $this->resolver->applyTransform('Jane Doe', FormMappingTransform::UppercaseText));
    }

    public function test_apply_transform_date_format_us_date(): void
    {
        $this->assertSame('01/20/2025', $this->resolver->applyTransform('2025-01-20', FormMappingTransform::DateFormatUsDate));
    }

    public function test_apply_transform_none_returns_value_unchanged(): void
    {
        $this->assertSame('Jane Doe', $this->resolver->applyTransform('Jane Doe', FormMappingTransform::None));
    }

    public function test_apply_transform_returns_null_for_null_value(): void
    {
        $this->assertNull($this->resolver->applyTransform(null, FormMappingTransform::UppercaseText));
    }

    public function test_resolves_matter_status_path(): void
    {
        $matter = Matter::factory()->create();

        $value = $this->resolver->resolve(FormMappingSourceEntity::Matter, 'matter.status', ['matter' => $matter]);

        $this->assertSame($matter->status->value, $value);
    }
}
