<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace\Intake;

use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Models\MarketplaceIntake;
use App\Marketplace\Services\MarketplaceIntakeService;
use App\Models\Document;
use App\Models\Firm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Mission 3 (MyAttorney Conversion + AI Intake), checkpoint 7 — the
 * public `/intake/{uuid}/documents` upload route. Mirrors
 * PublicIntakePageSecurityTest's own conventions.
 */
class MarketplaceIntakeDocumentUploadRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /**
     * @return array{0: Firm, 1: MarketplaceIntake}
     */
    private function eligibleIntake(): array
    {
        $firm = Firm::factory()->create();
        $directoryFirm = DirectoryFirm::factory()->member()->create(['firm_id' => $firm->id, 'accepting_inquiries' => true]);
        $intake = app(MarketplaceIntakeService::class)->startForDirectoryFirm($directoryFirm);

        return [$firm, $intake];
    }

    public function test_a_valid_upload_to_a_known_intake_creates_a_pending_document(): void
    {
        [$firm, $intake] = $this->eligibleIntake();
        $file = UploadedFile::fake()->create('contract.pdf', 10, 'application/pdf');

        $response = $this->post($this->myAttorneyUrl('/intake/'.$intake->uuid.'/documents'), ['file' => $file]);

        $response->assertRedirect();
        $count = $this->runWithFirmContext($firm, fn () => Document::query()->where('marketplace_intake_id', $intake->id)->count());
        $this->assertSame(1, $count);
    }

    public function test_an_unknown_uuid_returns_not_found_without_disclosing_anything(): void
    {
        $unknownUuid = (string) Str::uuid7();
        $file = UploadedFile::fake()->create('contract.pdf', 10, 'application/pdf');

        $response = $this->post($this->myAttorneyUrl('/intake/'.$unknownUuid.'/documents'), ['file' => $file]);

        $response->assertNotFound();
    }

    public function test_a_disallowed_extension_redirects_without_creating_a_document(): void
    {
        [$firm, $intake] = $this->eligibleIntake();
        $file = UploadedFile::fake()->create('malware.exe', 10);

        $response = $this->post($this->myAttorneyUrl('/intake/'.$intake->uuid.'/documents'), ['file' => $file]);

        $response->assertRedirect();
        $count = $this->runWithFirmContext($firm, fn () => Document::query()->where('marketplace_intake_id', $intake->id)->count());
        $this->assertSame(0, $count);
    }

    public function test_a_missing_file_is_a_validation_error_not_a_server_error(): void
    {
        [, $intake] = $this->eligibleIntake();

        $response = $this->post($this->myAttorneyUrl('/intake/'.$intake->uuid.'/documents'), []);

        $response->assertSessionHasErrors('file');
    }

    public function test_an_upload_to_a_terminal_intake_creates_no_document(): void
    {
        [$firm, $intake] = $this->eligibleIntake();
        $abandoned = app(MarketplaceIntakeService::class)->abandonExpired($firm, $intake);
        $file = UploadedFile::fake()->create('contract.pdf', 10, 'application/pdf');

        $this->post($this->myAttorneyUrl('/intake/'.$abandoned->uuid.'/documents'), ['file' => $file]);

        $count = $this->runWithFirmContext($firm, fn () => Document::query()->where('marketplace_intake_id', $intake->id)->count());
        $this->assertSame(0, $count);
    }

    public function test_repeated_uploads_past_the_throttle_limit_are_rejected(): void
    {
        [, $intake] = $this->eligibleIntake();

        $lastStatus = null;
        for ($i = 0; $i < 15; $i++) {
            $file = UploadedFile::fake()->create("contract{$i}.pdf", 10, 'application/pdf');
            $lastStatus = $this->post($this->myAttorneyUrl('/intake/'.$intake->uuid.'/documents'), ['file' => $file])->getStatusCode();
        }

        $this->assertSame(429, $lastStatus, 'The upload route must be rate-limited (throttle:10,1).');
    }
}
