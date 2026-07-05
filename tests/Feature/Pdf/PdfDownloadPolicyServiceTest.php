<?php

namespace Tests\Feature\Pdf;

use App\Enums\FirmUserRole;
use App\Enums\SignatureRequestStatus;
use App\Models\Document;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\SignatureRequest;
use App\Models\SignatureRequestRecipient;
use App\Services\PdfDownloadPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PdfDownloadPolicyServiceTest extends TestCase
{
    use RefreshDatabase;

    private PdfDownloadPolicyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PdfDownloadPolicyService();
    }

    public function test_firm_user_from_a_different_firm_is_denied(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $document = Document::factory()->clean()->create(['firm_id' => $firmA->id]);
        $viewer = FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $firmB->id]);

        $decision = $this->service->decideForFirmUser($viewer, $document);

        $this->assertFalse($decision->allowed);
    }

    public function test_unscanned_document_is_denied_even_for_the_owning_firm(): void
    {
        $firm = Firm::factory()->create();
        $document = Document::factory()->create(['firm_id' => $firm->id]);
        $viewer = FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $firm->id]);

        $decision = $this->service->decideForFirmUser($viewer, $document);

        $this->assertFalse($decision->allowed);
    }

    public function test_clean_document_within_the_owning_firm_is_allowed(): void
    {
        $firm = Firm::factory()->create();
        $document = Document::factory()->clean()->create(['firm_id' => $firm->id]);
        $viewer = FirmUser::factory()->role(FirmUserRole::Attorney)->create(['firm_id' => $firm->id]);

        $decision = $this->service->decideForFirmUser($viewer, $document);

        $this->assertTrue($decision->allowed);
    }

    public function test_recipient_with_no_relationship_to_the_document_is_denied(): void
    {
        $document = Document::factory()->clean()->create();
        $otherDocument = Document::factory()->clean()->create();
        $request = SignatureRequest::factory()->create(['document_id' => $document->id]);
        $recipient = SignatureRequestRecipient::factory()->forRequest($request)->create();

        $decision = $this->service->decideForRecipient($recipient, $otherDocument);

        $this->assertFalse($decision->allowed);
    }

    public function test_recipient_with_declined_status_is_denied_even_for_their_own_document(): void
    {
        $document = Document::factory()->clean()->create();
        $request = SignatureRequest::factory()->create(['document_id' => $document->id]);
        $recipient = SignatureRequestRecipient::factory()
            ->forRequest($request)
            ->status(SignatureRequestStatus::Declined)
            ->create();

        $decision = $this->service->decideForRecipient($recipient, $document);

        $this->assertFalse($decision->allowed);
    }

    public function test_active_recipient_on_their_own_clean_document_is_allowed(): void
    {
        $document = Document::factory()->clean()->create();
        $request = SignatureRequest::factory()->create(['document_id' => $document->id]);
        $recipient = SignatureRequestRecipient::factory()
            ->forRequest($request)
            ->status(SignatureRequestStatus::Sent)
            ->create();

        $decision = $this->service->decideForRecipient($recipient, $document);

        $this->assertTrue($decision->allowed);
    }
}
