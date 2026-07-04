<?php

namespace Tests\Feature\Foundation;

use App\Models\Firm;
use App\Models\FirmSettings;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FirmSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_can_be_created_via_factory(): void
    {
        $settings = FirmSettings::factory()->create();

        $this->assertDatabaseHas('firm_settings', ['id' => $settings->id]);
    }

    public function test_no_stripe_enabled_column_exists(): void
    {
        $settings = FirmSettings::factory()->create();

        $this->assertArrayNotHasKey('stripe_enabled', $settings->getAttributes());
    }

    public function test_only_one_settings_row_per_firm(): void
    {
        $firm = Firm::factory()->create();
        FirmSettings::factory()->forFirm($firm)->create();

        $this->expectException(QueryException::class);

        FirmSettings::factory()->forFirm($firm)->create();
    }

    public function test_json_columns_default_to_empty_object_at_application_layer(): void
    {
        $settings = new FirmSettings();

        $this->assertSame('{}', $settings->getAttributes()['branding_settings_json']);
        $this->assertSame('{}', $settings->getAttributes()['security_settings_json']);
    }
}
