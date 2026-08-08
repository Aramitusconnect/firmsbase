<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Enums\DocumentRequestItemStatus;
use App\Enums\DocumentRequestStatus;
use App\Enums\FirmUserRole;
use App\Filament\Firm\Resources\ClientResource\Pages\ViewClient;
use App\Filament\Firm\Resources\ClientResource\RelationManagers\DocumentRequestsRelationManager as ClientDocumentRequestsRelationManager;
use App\Filament\Firm\Resources\DocumentRequestResource;
use App\Filament\Firm\Resources\DocumentRequestResource\Actions\ApproveItemAction;
use App\Filament\Firm\Resources\DocumentRequestResource\Actions\MarkReceivedItemAction;
use App\Filament\Firm\Resources\DocumentRequestResource\Actions\WaiveItemAction;
use App\Filament\Firm\Resources\DocumentRequestResource\Pages\CreateDocumentRequest;
use App\Filament\Firm\Resources\DocumentRequestResource\Pages\EditDocumentRequest;
use App\Filament\Firm\Resources\DocumentRequestResource\Pages\ListDocumentRequests;
use App\Filament\Firm\Resources\DocumentRequestResource\Pages\ViewDocumentRequest;
use App\Filament\Firm\Resources\DocumentRequestResource\RelationManagers\ItemsRelationManager;
use App\Filament\Firm\Resources\MatterResource\Pages\ViewMatter;
use App\Filament\Firm\Resources\MatterResource\RelationManagers\DocumentRequestsRelationManager as MatterDocumentRequestsRelationManager;
use App\Models\Client;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestItem;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * DocumentRequestAccessTest — Firm Feature Manifest §5 (Tier1-F).
 * Proves role ceilings, that creation and every per-item status
 * transition really route through DocumentRequestService (never a bare
 * DocumentRequest(Item)::create()/update()), that this module never
 * declares a file-upload field or touches Storage, and the small RLS
 * regression checklist required for this module.
 */
final class DocumentRequestAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    // ------------------------------------------------------------
    // 1. canAccess() / role ceilings
    // ------------------------------------------------------------

    public function test_guest_cannot_access_the_document_request_resource(): void
    {
        $this->assertFalse(DocumentRequestResource::canAccess());
    }

    public function test_paralegal_can_create_a_document_request_but_receptionist_cannot(): void
    {
        $firmA = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::Paralegal);
        $this->assertTrue(DocumentRequestResource::canCreate());

        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmB, FirmUserRole::Receptionist);
        $this->assertFalse(DocumentRequestResource::canCreate());
    }

    public function test_billing_staff_can_view_but_not_create_a_document_request(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::BillingStaff);

        $this->assertTrue(DocumentRequestResource::canAccess());
        $this->assertFalse(DocumentRequestResource::canCreate());
    }

    public function test_list_page_renders_for_an_authorized_role(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(ListDocumentRequests::class));

        $test->assertSuccessful();
    }

    // ------------------------------------------------------------
    // 2. Create — DocumentRequestService::create() really creates the
    //    parent AND every requested item, transactionally
    // ------------------------------------------------------------

    public function test_create_document_request_persists_via_document_request_service_and_creates_items(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Attorney);
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create(['display_name' => 'Acme Corp']));

        $this->runWithFirmContext($firm, function () use ($client): void {
            $test = Livewire::test(CreateDocumentRequest::class);
            $test->fillForm([
                'client_id' => $client->id,
                'matter_id' => null,
                'title' => 'Immigration filing documents',
                'instructions' => 'Please provide the following.',
                'items' => [
                    ['label' => 'Passport copy', 'is_required' => true],
                    ['label' => 'Proof of address', 'is_required' => false],
                ],
            ]);
            $test->call('create');
            $test->assertHasNoFormErrors();
        });

        $request = $this->runWithFirmContext($firm, fn () => DocumentRequest::query()->where('title', 'Immigration filing documents')->first());
        $this->assertNotNull($request);
        $this->assertSame((int) $firm->id, (int) $request->firm_id);
        $this->assertSame(DocumentRequestStatus::Open, $request->status);

        $items = $this->runWithFirmContext($firm, fn () => $request->items()->orderBy('id')->get());
        $this->assertCount(2, $items, 'DocumentRequestService::create() must create every requested item in the same transaction.');
        $this->assertSame('Passport copy', $items[0]->label);
        $this->assertSame(DocumentRequestItemStatus::Requested, $items[0]->status);
        $this->assertTrue($items[0]->is_required);
        $this->assertFalse($items[1]->is_required);
    }

    public function test_document_request_form_never_declares_a_status_field(): void
    {
        $source = file_get_contents(app_path('Filament/Firm/Resources/DocumentRequestResource.php'));
        $this->assertIsString($source);

        preg_match('/public static function form\(.*?\n    \}/s', $source, $matches);
        $this->assertNotEmpty($matches);

        $this->assertStringNotContainsString("make('status')", $matches[0]);
    }

    // ------------------------------------------------------------
    // 3. Edit — narrow safe-field-only form
    // ------------------------------------------------------------

    public function test_edit_document_request_persists_a_safe_field_change_but_form_excludes_client_matter_status(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Attorney);
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $request = $this->runWithFirmContext($firm, fn () => DocumentRequest::factory()->forClient($client)->create(['title' => 'Original Title']));

        $this->runWithFirmContext($firm, function () use ($request): void {
            $test = Livewire::test(EditDocumentRequest::class, ['record' => $request->getRouteKey()]);
            $test->assertFormFieldDoesNotExist('client_id');
            $test->assertFormFieldDoesNotExist('matter_id');
            $test->assertFormFieldDoesNotExist('status');
            $test->assertFormFieldDoesNotExist('items');
            $test->fillForm(['title' => 'Updated Title']);
            $test->call('save');
            $test->assertHasNoFormErrors();
        });

        $fresh = $this->runWithFirmContext($firm, fn () => DocumentRequest::query()->find($request->id));
        $this->assertSame('Updated Title', $fresh->title);
        $this->assertSame((int) $client->id, (int) $fresh->client_id);
    }

    // ------------------------------------------------------------
    // 4. Per-item Actions — MarkReceived/Waive (this mission's required
    //    pair), wired directly to DocumentRequestService
    // ------------------------------------------------------------

    public function test_mark_received_action_visible_for_manage_role_and_hidden_for_receptionist(): void
    {
        $firmA = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::Attorney);
        $requestA = $this->runWithFirmContext($firmA, fn () => DocumentRequest::factory()->create(['firm_id' => $firmA->id]));
        $itemA = $this->runWithFirmContext($firmA, fn () => DocumentRequestItem::factory()->forRequest($requestA)->create());

        $this->runWithFirmContext($firmA, function () use ($requestA, $itemA): void {
            $test = Livewire::test(ItemsRelationManager::class, [
                'ownerRecord' => $requestA,
                'pageClass' => ViewDocumentRequest::class,
            ]);
            $test->assertTableActionVisible(MarkReceivedItemAction::getDefaultName(), $itemA);
        });

        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmB, FirmUserRole::Receptionist);
        $requestB = $this->runWithFirmContext($firmB, fn () => DocumentRequest::factory()->create(['firm_id' => $firmB->id]));
        $itemB = $this->runWithFirmContext($firmB, fn () => DocumentRequestItem::factory()->forRequest($requestB)->create());

        $this->runWithFirmContext($firmB, function () use ($requestB, $itemB): void {
            $test = Livewire::test(ItemsRelationManager::class, [
                'ownerRecord' => $requestB,
                'pageClass' => ViewDocumentRequest::class,
            ]);
            $test->assertTableActionHidden(MarkReceivedItemAction::getDefaultName(), $itemB);
        });
    }

    public function test_mark_received_action_calls_document_request_service_mark_submitted(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $request = $this->runWithFirmContext($firm, fn () => DocumentRequest::factory()->create(['firm_id' => $firm->id]));
        $item = $this->runWithFirmContext($firm, fn () => DocumentRequestItem::factory()->forRequest($request)->create());

        $this->runWithFirmContext($firm, function () use ($request, $item): void {
            $test = Livewire::test(ItemsRelationManager::class, [
                'ownerRecord' => $request,
                'pageClass' => ViewDocumentRequest::class,
            ]);
            $test->callTableAction(MarkReceivedItemAction::getDefaultName(), $item);
            $test->assertNotified('Item marked received');
        });

        $fresh = $this->runWithFirmContext($firm, fn () => DocumentRequestItem::query()->find($item->id));
        $this->assertSame(DocumentRequestItemStatus::Submitted, $fresh->status);
        $this->assertNotNull($fresh->submitted_at);
    }

    public function test_waive_item_action_calls_document_request_service_and_recomputes_parent_status(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $request = $this->runWithFirmContext($firm, fn () => DocumentRequestFactoryHelper::createForClient($client));
        $item = $this->runWithFirmContext($firm, fn () => $request->items()->first());

        $this->runWithFirmContext($firm, function () use ($request, $item): void {
            $test = Livewire::test(ItemsRelationManager::class, [
                'ownerRecord' => $request,
                'pageClass' => ViewDocumentRequest::class,
            ]);
            $test->mountTableAction(WaiveItemAction::getDefaultName(), $item);
            $test->setActionData(['reason' => 'No longer needed.']);
            $test->callMountedTableAction();
            $test->assertNotified('Item waived');
        });

        $freshItem = $this->runWithFirmContext($firm, fn () => DocumentRequestItem::query()->find($item->id));
        $this->assertSame(DocumentRequestItemStatus::Waived, $freshItem->status);
        $this->assertNotNull($freshItem->waived_at);
        $this->assertSame('No longer needed.', $freshItem->rejected_reason);

        // The single-item request must now be recomputed to Fulfilled —
        // proof this ran through DocumentRequestService::waive(), which
        // calls recomputeParentStatus(), not a bare
        // DocumentRequestItem::update().
        $freshRequest = $this->runWithFirmContext($firm, fn () => DocumentRequest::query()->find($request->id));
        $this->assertSame(DocumentRequestStatus::Fulfilled, $freshRequest->status);
    }

    public function test_approve_action_requires_a_submitted_or_under_review_item(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $request = $this->runWithFirmContext($firm, fn () => DocumentRequest::factory()->create(['firm_id' => $firm->id]));
        $requestedItem = $this->runWithFirmContext($firm, fn () => DocumentRequestItem::factory()->forRequest($request)->create());
        $submittedItem = $this->runWithFirmContext($firm, fn () => DocumentRequestItem::factory()->forRequest($request)->submitted()->create());

        $this->runWithFirmContext($firm, function () use ($request, $requestedItem, $submittedItem): void {
            $test = Livewire::test(ItemsRelationManager::class, [
                'ownerRecord' => $request,
                'pageClass' => ViewDocumentRequest::class,
            ]);
            $test->assertTableActionHidden(ApproveItemAction::getDefaultName(), $requestedItem);
            $test->assertTableActionVisible(ApproveItemAction::getDefaultName(), $submittedItem);
        });
    }

    // ------------------------------------------------------------
    // 5. No file storage / upload anywhere in this module
    // ------------------------------------------------------------

    public function test_no_file_upload_field_or_storage_call_exists_anywhere_in_this_module(): void
    {
        $directories = [
            app_path('Filament/Firm/Resources/DocumentRequestResource'),
            app_path('Filament/Firm/Resources/DocumentChaseRuleResource'),
        ];

        $files = [
            app_path('Filament/Firm/Resources/DocumentRequestResource.php'),
            app_path('Filament/Firm/Resources/DocumentChaseRuleResource.php'),
            app_path('Filament/Firm/Resources/ClientResource/RelationManagers/DocumentRequestsRelationManager.php'),
            app_path('Filament/Firm/Resources/MatterResource/RelationManagers/DocumentRequestsRelationManager.php'),
        ];

        foreach ($directories as $directory) {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $source = file_get_contents($file);
            $this->assertIsString($source);
            // Checks for the actual component instantiation, not the bare
            // word — this module's own docblocks legitimately name
            // `FileUpload`/`Storage::disk()` when explaining why neither is
            // used (see DocumentRequestResource's own class docblock).
            $this->assertStringNotContainsString('FileUpload::make(', $source, "{$file} must never declare a FileUpload field — no real document storage pipeline exists.");
            $this->assertStringNotContainsString('Storage::disk(', $source, "{$file} must never call Storage::disk() — no real document storage pipeline exists.");
        }
    }

    // ------------------------------------------------------------
    // 6. Relation managers on Client/Matter render
    // ------------------------------------------------------------

    public function test_client_document_requests_relation_manager_renders(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $request = $this->runWithFirmContext($firm, fn () => DocumentRequest::factory()->forClient($client)->create());

        $this->runWithFirmContext($firm, function () use ($client, $request): void {
            $test = Livewire::test(ClientDocumentRequestsRelationManager::class, [
                'ownerRecord' => $client,
                'pageClass' => ViewClient::class,
            ]);
            $test->assertOk();
            $test->assertCanSeeTableRecords([$request]);
        });
    }

    public function test_matter_document_requests_relation_manager_renders(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->create(['firm_id' => $firm->id, 'client_id' => $client->id]));
        $request = $this->runWithFirmContext($firm, fn () => DocumentRequest::factory()->create([
            'firm_id' => $firm->id,
            'client_id' => $client->id,
            'matter_id' => $matter->id,
        ]));

        $this->runWithFirmContext($firm, function () use ($matter, $request): void {
            $test = Livewire::test(MatterDocumentRequestsRelationManager::class, [
                'ownerRecord' => $matter,
                'pageClass' => ViewMatter::class,
            ]);
            $test->assertOk();
            $test->assertCanSeeTableRecords([$request]);
        });
    }

    // ------------------------------------------------------------
    // 7. Small RLS regression checklist (a/b/c/d)
    // ------------------------------------------------------------

    /** (a) a firm user can access its own DocumentRequest records. */
    public function test_a_firm_user_can_access_its_own_document_requests(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $request = $this->runWithFirmContext($firm, fn () => DocumentRequest::factory()->create(['firm_id' => $firm->id]));

        $response = $this->runWithFirmContext($firm, fn () => $this->get(DocumentRequestResource::getUrl('view', ['record' => $request])));

        $response->assertSuccessful();
    }

    /** (b) a foreign firm's DocumentRequest is not returned by the list/query. */
    public function test_list_page_shows_only_this_firms_document_requests(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $requestA = $this->runWithFirmContext($firmA, fn () => DocumentRequest::factory()->create(['firm_id' => $firmA->id]));
        $requestB = $this->runWithFirmContext($firmB, fn () => DocumentRequest::factory()->create(['firm_id' => $firmB->id]));

        $test = $this->runWithFirmContext($firmA, fn () => Livewire::test(ListDocumentRequests::class));

        $test->assertSuccessful();
        $test->assertCanSeeTableRecords([$requestA]);
        $test->assertCanNotSeeTableRecords([$requestB]);
    }

    public function test_real_rls_proof_a_raw_query_under_firm_a_context_cannot_read_firm_bs_document_request_row(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $requestA = $this->runWithFirmContext($firmA, fn () => DocumentRequest::factory()->create(['firm_id' => $firmA->id]));
        $requestB = $this->runWithFirmContext($firmB, fn () => DocumentRequest::factory()->create(['firm_id' => $firmB->id]));

        $visibleIds = $this->runWithFirmContext($firmA, fn () => DB::table('document_requests')->pluck('id')->all());

        $this->assertContains($requestA->id, $visibleIds);
        $this->assertNotContains($requestB->id, $visibleIds, "Firm A's session must never read Firm B's document request row.");
    }

    /** (c) a foreign client cannot be selected via the client_id select. */
    public function test_client_select_options_never_include_a_foreign_firms_client(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $clientA = $this->runWithFirmContext($firmA, fn () => Client::factory()->forFirm($firmA)->create());
        $clientB = $this->runWithFirmContext($firmB, fn () => Client::factory()->forFirm($firmB)->create());

        $this->runWithFirmContext($firmA, function () use ($clientA, $clientB): void {
            $visibleClientIds = Client::query()->pluck('id')->all();

            $this->assertContains($clientA->id, $visibleClientIds);
            $this->assertNotContains($clientB->id, $visibleClientIds, "Firm A's client_id options must never include Firm B's client.");
        });
    }

    /** (d) direct navigation to a foreign record's URL is blocked. */
    public function test_direct_url_guess_of_another_firms_document_request_never_succeeds(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $requestB = $this->runWithFirmContext($firmB, fn () => DocumentRequest::factory()->create(['firm_id' => $firmB->id]));

        $response = $this->runWithFirmContext($firmA, fn () => $this->get(DocumentRequestResource::getUrl('view', ['record' => $requestB])));

        $response->assertNotFound();
    }

    private function actingAsRole(Firm $firm, FirmUserRole $role): FirmUser
    {
        $firmUser = $this->runWithFirmContext(
            $firm,
            fn () => FirmUser::factory()->forFirm($firm)->forUser(User::factory()->create())->role($role)->create()
        );

        $this->actingAs($firmUser->user);

        return $firmUser;
    }
}

/**
 * DocumentRequestFactoryHelper — tiny local helper so
 * test_waive_item_action_calls_document_request_service_and_recomputes_parent_status
 * exercises a request with exactly ONE item (so waiving it alone
 * flips the parent to Fulfilled, proving recomputeParentStatus() ran)
 * without duplicating DocumentRequestFactory's own definition().
 */
final class DocumentRequestFactoryHelper
{
    public static function createForClient(Client $client): DocumentRequest
    {
        $request = DocumentRequest::factory()->forClient($client)->create();
        DocumentRequestItem::factory()->forRequest($request)->create(['label' => 'Passport copy']);

        return $request->fresh('items');
    }
}
