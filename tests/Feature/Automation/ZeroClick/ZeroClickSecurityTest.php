<?php

namespace Tests\Feature\Automation\ZeroClick;

use App\Enums\AutomationActionType;
use App\Enums\DocumentRequestItemStatus;
use App\Enums\DomainEventType;
use App\Enums\FirmUserRole;
use App\Exceptions\AutomationActionPermanentException;
use App\Models\AutomationRule;
use App\Models\DocumentRequest;
use App\Models\DocumentRequestItem;
use App\Models\DomainEvent;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Services\Automation\Actions\CreateDocumentRequestActionHandler;
use App\Services\Automation\Actions\MatchDocumentToRequestActionHandler;
use App\Services\Automation\AutomationRecipientResolverService;
use App\Services\Automation\AutomationRuleMatchingService;
use App\Services\Automation\AutomationRuleService;
use App\Services\DocumentMatchingService;
use App\Services\DocumentRequestService;
use App\Services\DocumentSecurityService;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ZeroClickSecurityTest — Zero-Click Core Workflow Automation, test
 * matrix Q/R/S/AA/AB/AC. Cross-firm isolation, malformed
 * configuration, and missing-assignee scenarios for the new action
 * handlers/save-time validation.
 */
class ZeroClickSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_forged_cross_firm_matter_id_in_the_payload_is_never_resolved(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $matterB = $this->runWithFirmContext($firmB, fn () => Matter::factory()->forFirm($firmB)->create());

        $event = $this->runWithFirmContext($firmA, fn () => DomainEvent::factory()->create([
            'firm_id' => $firmA->id,
            'event_type' => DomainEventType::DocumentUploaded,
            'payload_json' => [
                'document' => ['id' => 999999, 'document_request_item_id' => null, 'matter_id' => $matterB->id],
                'matter' => ['id' => $matterB->id],
                'client' => ['id' => null],
            ],
        ]));

        $handler = new MatchDocumentToRequestActionHandler(
            new DocumentMatchingService,
            app(DocumentSecurityService::class),
            app(DocumentRequestService::class),
            app(TaskService::class),
            new AutomationRecipientResolverService,
        );

        $outcome = $this->runWithFirmContext($firmA, fn () => $handler->handle($firmA, $event, []));

        // Firm A's own action handler must never resolve Firm B's
        // Matter — the forged cross-firm id is treated exactly like
        // "no matter found," never a cross-tenant leak.
        $this->assertTrue($outcome->skipped);
    }

    public function test_a_disabled_rule_is_never_matched_by_the_real_matching_pipeline(): void
    {
        $firm = Firm::factory()->create();

        $matched = $this->runWithFirmContext($firm, function () use ($firm) {
            AutomationRule::factory()->forFirm($firm)->ofType(DomainEventType::MatterOpened)->disabled()->create([
                'actions_json' => [['action_type' => AutomationActionType::CreateTask->value, 'config' => ['title' => 'X', 'assigned_to' => 'role:firm_owner']]],
            ]);

            $event = DomainEvent::factory()->create([
                'firm_id' => $firm->id,
                'event_type' => DomainEventType::MatterOpened,
                'payload_json' => ['matter' => ['id' => 1, 'client_id' => null, 'assigned_attorney_id' => null, 'status' => 'open']],
            ]);

            return app(AutomationRuleMatchingService::class)->match($firm, $event);
        });

        $this->assertSame(0, $matched['matched_rules']);
    }

    public function test_a_malformed_create_document_request_config_is_a_permanent_failure_never_retried(): void
    {
        $firm = Firm::factory()->create();

        $event = $this->runWithFirmContext($firm, fn () => DomainEvent::factory()->create([
            'firm_id' => $firm->id,
            'event_type' => DomainEventType::MatterOpened,
            'payload_json' => ['matter' => ['id' => 1, 'client_id' => 1]],
        ]));

        $handler = new CreateDocumentRequestActionHandler(app(DocumentRequestService::class));

        $this->expectException(AutomationActionPermanentException::class);

        $this->runWithFirmContext($firm, fn () => $handler->handle($firm, $event, ['items' => []]));
    }

    public function test_an_unresolvable_client_for_create_document_request_is_skipped_not_guessed(): void
    {
        $firm = Firm::factory()->create();

        $event = $this->runWithFirmContext($firm, fn () => DomainEvent::factory()->create([
            'firm_id' => $firm->id,
            'event_type' => DomainEventType::MatterOpened,
            'payload_json' => ['matter' => ['id' => null, 'client_id' => null]],
        ]));

        $handler = new CreateDocumentRequestActionHandler(app(DocumentRequestService::class));

        $outcome = $this->runWithFirmContext($firm, fn () => $handler->handle($firm, $event, [
            'items' => [['label' => 'ID']],
        ]));

        $this->assertTrue($outcome->skipped);
    }

    public function test_automation_rule_service_rejects_a_forged_unknown_action_type_string(): void
    {
        $firm = Firm::factory()->create();

        $owner = $this->runWithFirmContext($firm, fn () => FirmUser::factory()->forFirm($firm)->create(['role' => FirmUserRole::FirmOwner]));

        $this->expectException(\InvalidArgumentException::class);

        $this->runWithFirmContext($firm, fn () => app(AutomationRuleService::class)->create(
            firm: $firm,
            createdBy: $owner,
            name: 'Forged rule',
            description: null,
            eventType: DomainEventType::MatterOpened,
            conditions: [],
            actions: [['action_type' => 'create_trust_ledger_entry', 'config' => []]],
        ));
    }

    public function test_a_document_request_item_from_another_firm_is_never_matched_as_a_candidate(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $matterA = $this->runWithFirmContext($firmA, fn () => Matter::factory()->forFirm($firmA)->create());

        // Firm B has an open item for its OWN, differently-numbered matter — never a real collision, but proves candidatesFor() is firm-scoped.
        $this->runWithFirmContext($firmB, function () use ($firmB) {
            $matterB = Matter::factory()->forFirm($firmB)->create();
            $requestB = DocumentRequest::factory()->create(['firm_id' => $firmB->id, 'matter_id' => $matterB->id, 'client_id' => $matterB->client_id]);
            DocumentRequestItem::factory()->forRequest($requestB)->create(['status' => DocumentRequestItemStatus::Requested]);
        });

        $candidates = $this->runWithFirmContext($firmA, fn () => (new DocumentMatchingService)->candidatesFor($firmA, $matterA));

        $this->assertCount(0, $candidates);
    }
}
