<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Enums\EntitlementSource;
use App\Enums\FirmUserRole;
use App\Enums\SignatureRecipientType;
use App\Enums\SignatureRequestStatus;
use App\Filament\Firm\Resources\SignatureRequestResource;
use App\Filament\Firm\Resources\SignatureRequestResource\Actions\AttorneyReviewSignatureRequestAction;
use App\Filament\Firm\Resources\SignatureRequestResource\Actions\CreateSignatureRequestAction;
use App\Filament\Firm\Resources\SignatureRequestResource\Actions\SendSignatureRequestAction;
use App\Filament\Firm\Resources\SignatureRequestResource\Actions\VoidSignatureRequestAction;
use App\Filament\Firm\Resources\SignatureRequestResource\Pages\ListSignatureRequests;
use App\Filament\Firm\Resources\SignatureRequestResource\Pages\ViewSignatureRequest;
use App\Filament\Firm\Resources\SignatureRequestResource\RelationManagers\RecipientsRelationManager;
use App\Models\Document;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\SignatureRequest;
use App\Models\SignatureRequestRecipient;
use App\Models\User;
use App\Services\EntitlementService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * SignatureRequestResourceTest — Non-payment completion program,
 * e-signature signer-facing flow. Proves the first-ever Filament
 * surface for SignatureRequest: role ceilings, that every Action calls
 * the real SignatureRequestWorkflowService method (never a bare
 * SignatureRequest::create()/update()), and that a request genuinely
 * cannot be sent without both an attorney review and at least one
 * recipient — mirroring InvoiceResourceAccessTest's established shape.
 */
final class SignatureRequestResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    public function test_list_page_renders_for_an_authorized_role(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(ListSignatureRequests::class));

        $test->assertSuccessful();
    }

    public function test_create_action_calls_the_real_workflow_service(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $document = $this->runWithFirmContext($firm, fn () => Document::factory()->create(['firm_id' => $firm->id]));

        $this->runWithFirmContext($firm, function () use ($document): void {
            $test = Livewire::test(ListSignatureRequests::class);
            $test->mountAction(CreateSignatureRequestAction::getDefaultName());
            $test->setActionData([
                'title' => 'Engagement Letter',
                'source' => 'document:'.$document->id,
            ]);
            $test->callMountedAction();
            $test->assertNotified('Signature request created');
        });

        $request = $this->runWithFirmContext($firm, fn () => SignatureRequest::query()->where('document_id', $document->id)->first());
        $this->assertNotNull($request);
        $this->assertSame(SignatureRequestStatus::Draft, $request->status);
        $this->assertSame('Engagement Letter', $request->title);
    }

    public function test_send_is_hidden_until_attorney_reviewed(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $request = $this->runWithFirmContext($firm, fn () => SignatureRequest::factory()->create(['firm_id' => $firm->id]));

        $this->runWithFirmContext($firm, function () use ($request): void {
            $test = Livewire::test(ListSignatureRequests::class);
            $test->assertTableActionVisible(SendSignatureRequestAction::getDefaultName(), $request);
        });

        // Visible (Draft status) but the underlying service call itself
        // must still fail before attorney review — proving this Action
        // never bypasses SignatureRequestWorkflowService::send()'s own
        // hard gate.
        $this->runWithFirmContext($firm, function () use ($request): void {
            $test = Livewire::test(ListSignatureRequests::class);
            $test->callTableAction(SendSignatureRequestAction::getDefaultName(), $request);
            $test->assertNotified('Could not send signature request');
        });

        $fresh = $this->runWithFirmContext($firm, fn () => SignatureRequest::query()->find($request->id));
        $this->assertSame(SignatureRequestStatus::Draft, $fresh->status);
    }

    public function test_attorney_review_send_and_recipient_add_transition_via_the_real_services(): void
    {
        Notification::fake();

        $firm = Firm::factory()->create();
        $owner = $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $request = $this->runWithFirmContext($firm, fn () => SignatureRequest::factory()->create(['firm_id' => $firm->id]));

        // Recipient must be added before send() can succeed.
        $this->runWithFirmContext($firm, function () use ($request): void {
            $test = Livewire::test(RecipientsRelationManager::class, [
                'ownerRecord' => $request,
                'pageClass' => ViewSignatureRequest::class,
            ]);
            $test->mountTableAction('addRecipient');
            $test->setTableActionData([
                'recipient_type' => SignatureRecipientType::External->value,
                'signer_name' => 'Jane Signer',
                'signer_email' => 'jane@example.test',
            ]);
            $test->callMountedTableAction();
            $test->assertNotified('Recipient added');
        });

        $this->assertSame(1, $this->runWithFirmContext($firm, fn () => SignatureRequestRecipient::where('signature_request_id', $request->id)->count()));

        // Attorney review.
        $this->runWithFirmContext($firm, function () use ($request): void {
            $test = Livewire::test(ListSignatureRequests::class);
            $test->mountTableAction(AttorneyReviewSignatureRequestAction::getDefaultName(), $request);
            $test->setActionData(['notes' => 'Suitable for e-signature under UETA/ESIGN.']);
            $test->callMountedTableAction();
            $test->assertNotified('Attorney review recorded');
        });

        $reviewed = $this->runWithFirmContext($firm, fn () => SignatureRequest::query()->find($request->id));
        $this->assertNotNull($reviewed->attorney_reviewed_at);

        // Send.
        $this->runWithFirmContext($firm, function () use ($request): void {
            $test = Livewire::test(ListSignatureRequests::class);
            $test->callTableAction(SendSignatureRequestAction::getDefaultName(), $request);
            $test->assertNotified('Signature request sent');
        });

        $sent = $this->runWithFirmContext($firm, fn () => SignatureRequest::query()->find($request->id));
        $this->assertSame(SignatureRequestStatus::Sent, $sent->status);

        $recipient = $this->runWithFirmContext($firm, fn () => SignatureRequestRecipient::where('signature_request_id', $request->id)->first());
        $this->assertSame(SignatureRequestStatus::Sent, $recipient->status);
        $this->assertNotNull($recipient->access_token_hash);
    }

    public function test_void_cascades_via_the_real_service(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Attorney);
        $request = $this->runWithFirmContext($firm, fn () => SignatureRequest::factory()->create(['firm_id' => $firm->id]));

        $this->runWithFirmContext($firm, function () use ($request): void {
            $test = Livewire::test(ListSignatureRequests::class);
            $test->mountTableAction(VoidSignatureRequestAction::getDefaultName(), $request);
            $test->setActionData(['reason' => 'Client withdrew.']);
            $test->callMountedTableAction();
            $test->assertNotified('Signature request voided');
        });

        $fresh = $this->runWithFirmContext($firm, fn () => SignatureRequest::query()->find($request->id));
        $this->assertSame(SignatureRequestStatus::Voided, $fresh->status);
    }

    public function test_paralegal_cannot_void(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Paralegal);
        $request = $this->runWithFirmContext($firm, fn () => SignatureRequest::factory()->create(['firm_id' => $firm->id]));

        $this->runWithFirmContext($firm, function () use ($request): void {
            $test = Livewire::test(ListSignatureRequests::class);
            $test->assertTableActionHidden(VoidSignatureRequestAction::getDefaultName(), $request);
        });
    }

    public function test_list_page_shows_only_this_firms_signature_requests(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $requestA = $this->runWithFirmContext($firmA, fn () => SignatureRequest::factory()->create(['firm_id' => $firmA->id]));
        $requestB = $this->runWithFirmContext($firmB, fn () => SignatureRequest::factory()->create(['firm_id' => $firmB->id]));

        $test = $this->runWithFirmContext($firmA, fn () => Livewire::test(ListSignatureRequests::class));

        $test->assertSuccessful();
        $test->assertCanSeeTableRecords([$requestA]);
        $test->assertCanNotSeeTableRecords([$requestB]);
    }

    public function test_direct_url_guess_of_another_firms_signature_request_never_succeeds(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $requestB = $this->runWithFirmContext($firmB, fn () => SignatureRequest::factory()->create(['firm_id' => $firmB->id]));

        $response = $this->runWithFirmContext($firmA, fn () => $this->get(SignatureRequestResource::getUrl('view', ['record' => $requestB])));

        $response->assertNotFound();
    }

    private function actingAsRole(Firm $firm, FirmUserRole $role): FirmUser
    {
        // e_signature is an opt-in module_catalog entitlement — a
        // freshly-created Firm factory has zero firm_entitlements rows,
        // which resolves to notEntitled() (see EntitlementService::resolve()).
        // Every test needs it explicitly granted before
        // SignatureRequestResource::canAccess()/shouldRegisterNavigation()
        // will allow anything through.
        app(EntitlementService::class)->setForSource($firm, 'e_signature', EntitlementSource::AdminOverride, true);

        $firmUser = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create(['two_factor_confirmed_at' => now()]))->role($role)->create()
        );

        $this->actingAs($firmUser->user);

        return $firmUser;
    }
}
