<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Enums\FirmUserRole;
use App\Enums\FirmUserStatus;
use App\Models\Document;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * DocumentDownloadControllerTest — Mission 3 (Document Center
 * Completion), section 3.5. Proves the real, session-authenticated
 * `firm.documents.download` route: a 200 with the real file content
 * and correct filename for an authorized actor, and a 403 (not a
 * silent 404/redirect) for an unauthorized one — the route itself only
 * proves "some authenticated firm user," `DocumentSecurityService::
 * canBeDownloadedBy()` (already exhaustively proven at the service
 * layer by DocumentDownloadAuthorizationTest) is the real boundary this
 * test proves is actually wired to the HTTP layer.
 */
final class DocumentDownloadControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_authorized_firm_owner_can_download_a_matter_scoped_document(): void
    {
        Storage::fake('local');

        $firm = Firm::factory()->create();
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $document = $this->runWithFirmContext($firm, fn () => Document::factory()->clean()->create([
            'firm_id' => $firm->id,
            'matter_id' => $matter->id,
            'original_filename' => 'evidence.pdf',
            'storage_disk' => 'local',
            'storage_path' => "documents/{$firm->id}/{$matter->id}/evidence.pdf",
        ]));
        Storage::disk('local')->put($document->storage_path, 'a real file body');

        $owner = $this->makeFirmUser($firm, FirmUserRole::FirmOwner);

        $response = $this->actingAs($owner->user)->get(route('firm.documents.download', $document));

        $response->assertOk();
        $this->assertStringContainsString('evidence.pdf', (string) $response->headers->get('content-disposition'));
        $this->assertSame('a real file body', $response->streamedContent());
    }

    public function test_a_paralegal_without_a_matter_assignment_gets_a_403_not_the_file(): void
    {
        Storage::fake('local');

        $firm = Firm::factory()->create();
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $document = $this->runWithFirmContext($firm, fn () => Document::factory()->create([
            'firm_id' => $firm->id,
            'matter_id' => $matter->id,
            'storage_disk' => 'local',
            'storage_path' => "documents/{$firm->id}/{$matter->id}/secret.pdf",
        ]));
        Storage::disk('local')->put($document->storage_path, 'a real file body');

        $paralegal = $this->makeFirmUser($firm, FirmUserRole::Paralegal);

        $response = $this->actingAs($paralegal->user)->get(route('firm.documents.download', $document));

        $response->assertForbidden();
    }

    public function test_a_user_from_a_different_firm_cannot_download_the_document(): void
    {
        Storage::fake('local');

        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $matterB = $this->runWithFirmContext($firmB, fn () => Matter::factory()->forFirm($firmB)->create());
        $documentB = $this->runWithFirmContext($firmB, fn () => Document::factory()->create([
            'firm_id' => $firmB->id,
            'matter_id' => $matterB->id,
            'storage_disk' => 'local',
            'storage_path' => "documents/{$firmB->id}/{$matterB->id}/other-firms.pdf",
        ]));
        Storage::disk('local')->put($documentB->storage_path, 'a real file body');

        $ownerOfA = $this->makeFirmUser($firmA, FirmUserRole::FirmOwner);

        $response = $this->actingAs($ownerOfA->user)->get(route('firm.documents.download', $documentB));

        // `documents` carries FORCE ROW LEVEL SECURITY. The route's
        // tenant-context middleware resolves context from the
        // REQUESTING user's own firm (firmA) — a document belonging
        // to a different firm (firmB) is therefore invisible under
        // RLS at the implicit-model-binding step itself, before
        // DocumentSecurityService::canBeDownloadedBy() is even
        // reached: a genuine cross-firm request 404s rather than
        // 403s. Either way the file is never served — this asserts
        // the specific, intentional RLS-invisibility shape.
        $response->assertNotFound();
        $this->assertNotSame(200, $response->getStatusCode());
    }

    public function test_a_guest_is_redirected_to_login_not_given_the_file(): void
    {
        Storage::fake('local');

        $firm = Firm::factory()->create();
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->create());
        $document = $this->runWithFirmContext($firm, fn () => Document::factory()->create([
            'firm_id' => $firm->id,
            'matter_id' => $matter->id,
        ]));

        $response = $this->get(route('firm.documents.download', $document));

        $response->assertRedirect();
        $this->assertNotSame(200, $response->getStatusCode());
    }

    private function makeFirmUser(Firm $firm, FirmUserRole $role): FirmUser
    {
        return $this->runWithFirmContext($firm, fn () => FirmUser::factory()
            ->forFirm($firm)
            ->forUser(User::factory()->create())
            ->create(['role' => $role, 'status' => FirmUserStatus::Active]));
    }
}
