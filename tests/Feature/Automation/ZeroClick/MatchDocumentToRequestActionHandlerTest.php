<?php

namespace Tests\Feature\Automation\ZeroClick;

use App\Enums\DocumentRequestItemStatus;
use App\Enums\DomainEventType;
use App\Enums\FirmUserRole;
use App\Models\Document;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestItem;
use App\Models\DomainEvent;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\Task;
use App\Services\Automation\Actions\MatchDocumentToRequestActionHandler;
use App\Services\Automation\AutomationActionOutcome;
use App\Services\Automation\AutomationRecipientResolverService;
use App\Services\DocumentMatchingService;
use App\Services\DocumentRequestService;
use App\Services\DocumentSecurityService;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * MatchDocumentToRequestActionHandlerTest — Zero-Click Core Workflow
 * Automation, test matrix A/B. Proves the conservative-matching
 * contract: exactly one open item is auto-linked + marked submitted;
 * two or more creates a review Task; zero is a genuine no-op.
 */
class MatchDocumentToRequestActionHandlerTest extends TestCase
{
    use RefreshDatabase;

    private function handler(): MatchDocumentToRequestActionHandler
    {
        return new MatchDocumentToRequestActionHandler(
            new DocumentMatchingService,
            app(DocumentSecurityService::class),
            app(DocumentRequestService::class),
            app(TaskService::class),
            new AutomationRecipientResolverService,
        );
    }

    private function domainEventFor(Firm $firm, Matter $matter, Document $document): DomainEvent
    {
        return $this->runWithFirmContext($firm, fn () => DomainEvent::factory()->create([
            'firm_id' => $firm->id,
            'event_type' => DomainEventType::DocumentUploaded,
            'subject_type' => $document->getMorphClass(),
            'subject_id' => $document->id,
            'payload_json' => [
                'document' => [
                    'id' => $document->id,
                    'file_name' => $document->original_filename,
                    'document_request_item_id' => $document->document_request_item_id,
                    'matter_id' => $matter->id,
                ],
                'matter' => ['id' => $matter->id, 'assigned_attorney_id' => $matter->assigned_attorney_id],
                'client' => ['id' => $matter->client_id],
            ],
        ]));
    }

    public function test_exact_single_open_item_is_auto_matched_and_marked_submitted(): void
    {
        $firm = Firm::factory()->create();

        [$event, $itemId] = $this->runWithFirmContext($firm, function () use ($firm) {
            $matter = Matter::factory()->forFirm($firm)->create();
            $request = DocumentRequest::factory()->create(['firm_id' => $firm->id, 'matter_id' => $matter->id, 'client_id' => $matter->client_id]);
            $item = DocumentRequestItem::factory()->forRequest($request)->create(['status' => DocumentRequestItemStatus::Requested]);
            $document = Document::factory()->create(['firm_id' => $firm->id, 'matter_id' => $matter->id]);

            return [$this->domainEventFor($firm, $matter, $document), $item->id];
        });

        $outcome = $this->runWithFirmContext($firm, fn () => $this->handler()->handle($firm, $event, []));

        $this->assertInstanceOf(AutomationActionOutcome::class, $outcome);
        $this->assertFalse($outcome->skipped);

        $this->runWithFirmContext($firm, function () use ($itemId) {
            $item = DocumentRequestItem::query()->find($itemId);
            $this->assertSame(DocumentRequestItemStatus::Submitted, $item->status);
        });
    }

    public function test_two_open_items_creates_a_review_task_and_never_auto_completes(): void
    {
        $firm = Firm::factory()->create();

        [$event, $item1Id, $item2Id] = $this->runWithFirmContext($firm, function () use ($firm) {
            $attorney = FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::Attorney]);
            $matter = Matter::factory()->forFirm($firm)->create(['assigned_attorney_id' => $attorney->user_id]);
            $request = DocumentRequest::factory()->create(['firm_id' => $firm->id, 'matter_id' => $matter->id, 'client_id' => $matter->client_id]);
            $item1 = DocumentRequestItem::factory()->forRequest($request)->create(['status' => DocumentRequestItemStatus::Requested]);
            $item2 = DocumentRequestItem::factory()->forRequest($request)->create(['status' => DocumentRequestItemStatus::Viewed]);
            $document = Document::factory()->create(['firm_id' => $firm->id, 'matter_id' => $matter->id]);

            return [$this->domainEventFor($firm, $matter, $document), $item1->id, $item2->id];
        });

        $outcome = $this->runWithFirmContext($firm, fn () => $this->handler()->handle($firm, $event, []));

        $this->assertFalse($outcome->skipped);
        $this->assertSame(Task::class, $outcome->resultReferenceType);

        $this->runWithFirmContext($firm, function () use ($item1Id, $item2Id) {
            $this->assertSame(DocumentRequestItemStatus::Requested, DocumentRequestItem::query()->find($item1Id)->status);
            $this->assertSame(DocumentRequestItemStatus::Viewed, DocumentRequestItem::query()->find($item2Id)->status);
        });
    }

    public function test_zero_open_items_is_a_genuine_no_op(): void
    {
        $firm = Firm::factory()->create();

        $event = $this->runWithFirmContext($firm, function () use ($firm) {
            $matter = Matter::factory()->forFirm($firm)->create();
            $document = Document::factory()->create(['firm_id' => $firm->id, 'matter_id' => $matter->id]);

            return $this->domainEventFor($firm, $matter, $document);
        });

        $outcome = $this->runWithFirmContext($firm, fn () => $this->handler()->handle($firm, $event, []));

        $this->assertTrue($outcome->skipped);
    }

    public function test_a_document_already_linked_at_upload_time_is_skipped(): void
    {
        $firm = Firm::factory()->create();

        $event = $this->runWithFirmContext($firm, function () use ($firm) {
            $matter = Matter::factory()->forFirm($firm)->create();
            $request = DocumentRequest::factory()->create(['firm_id' => $firm->id, 'matter_id' => $matter->id, 'client_id' => $matter->client_id]);
            $item = DocumentRequestItem::factory()->forRequest($request)->create(['status' => DocumentRequestItemStatus::Requested]);
            $document = Document::factory()->create(['firm_id' => $firm->id, 'matter_id' => $matter->id, 'document_request_item_id' => $item->id]);

            return $this->domainEventFor($firm, $matter, $document);
        });

        $outcome = $this->runWithFirmContext($firm, fn () => $this->handler()->handle($firm, $event, []));

        $this->assertTrue($outcome->skipped);
    }
}
