<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Enums\DocumentChaseRuleStatus;
use App\Enums\FirmUserRole;
use App\Filament\Firm\Resources\DocumentChaseRuleResource;
use App\Filament\Firm\Resources\DocumentChaseRuleResource\Pages\CreateDocumentChaseRule;
use App\Filament\Firm\Resources\DocumentChaseRuleResource\Pages\EditDocumentChaseRule;
use App\Filament\Firm\Resources\DocumentChaseRuleResource\Pages\ListDocumentChaseRules;
use App\Filament\Firm\Resources\DocumentChaseRuleResource\Pages\ViewDocumentChaseRule;
use App\Filament\Firm\Resources\DocumentChaseRuleResource\RelationManagers\ChaseEventsRelationManager;
use App\Models\DocumentChaseEvent;
use App\Models\DocumentChaseRule;
use App\Models\DocumentRequestItem;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * DocumentChaseAccessTest — Firm Feature Manifest §5 (Tier1-F, Document
 * Chase half). Proves the narrower role ceiling for chase-rule
 * management, that create/edit really persist via direct Eloquent
 * write (the correct path — no dedicated service exists for this
 * model), the honest "no reminder actually sent" copy is present, that
 * no "send now"/dispatch action exists anywhere in this module, that
 * the event log is genuinely read-only, and the small RLS regression
 * checklist required for this module.
 */
final class DocumentChaseAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('firm'));
    }

    // ------------------------------------------------------------
    // 1. canAccess() / role ceilings — narrower than DocumentRequest
    //    (FirmOwner/Attorney only)
    // ------------------------------------------------------------

    public function test_guest_cannot_access_the_document_chase_rule_resource(): void
    {
        $this->assertFalse(DocumentChaseRuleResource::canAccess());
    }

    public function test_attorney_can_create_a_chase_rule_but_paralegal_cannot(): void
    {
        $firmA = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::Attorney);
        $this->assertTrue(DocumentChaseRuleResource::canCreate());

        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmB, FirmUserRole::Paralegal);
        $this->assertFalse(DocumentChaseRuleResource::canCreate());
    }

    public function test_paralegal_can_view_but_not_create_a_chase_rule(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Paralegal);

        $this->assertTrue(DocumentChaseRuleResource::canAccess());
        $this->assertFalse(DocumentChaseRuleResource::canCreate());
    }

    // ------------------------------------------------------------
    // 2. Honest copy — every page in this resource must carry it
    // ------------------------------------------------------------

    private const HONEST_COPY = 'Automatic reminder sending is not yet enabled';

    public function test_list_page_renders_and_carries_the_honest_no_reminder_sent_copy(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $test = $this->runWithFirmContext($firm, fn () => Livewire::test(ListDocumentChaseRules::class));

        $test->assertSuccessful();
        $this->assertStringContainsString(self::HONEST_COPY, (new ListDocumentChaseRules)->getSubheading());
    }

    public function test_view_page_carries_the_honest_no_reminder_sent_copy(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $rule = $this->runWithFirmContext($firm, fn () => DocumentChaseRule::factory()->forFirm($firm)->create());

        $this->runWithFirmContext($firm, function () use ($rule): void {
            $test = Livewire::test(ViewDocumentChaseRule::class, ['record' => $rule->getRouteKey()]);
            $test->assertSuccessful();
        });

        $this->assertStringContainsString(self::HONEST_COPY, (new ViewDocumentChaseRule)->getSubheading());
    }

    // ------------------------------------------------------------
    // 3. Create/Edit — direct Eloquent write (no dedicated service
    //    exists for this model — confirmed by source read)
    // ------------------------------------------------------------

    public function test_create_chase_rule_persists_with_normalized_int_fields(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);

        $this->runWithFirmContext($firm, function (): void {
            $test = Livewire::test(CreateDocumentChaseRule::class);
            $test->fillForm([
                'name' => 'Immigration cadence',
                'status' => DocumentChaseRuleStatus::Active->value,
                'applies_to' => 'immigration',
                'channel' => 'email',
                'reminder_offsets_days' => ['7', '3', '1'],
                'max_reminders' => '3',
                'escalate_after_days' => '14',
            ]);
            $test->call('create');
            $test->assertHasNoFormErrors();
        });

        $rule = $this->runWithFirmContext($firm, fn () => DocumentChaseRule::query()->where('name', 'Immigration cadence')->first());
        $this->assertNotNull($rule);
        $this->assertSame((int) $firm->id, (int) $rule->firm_id);
        $this->assertSame([7, 3, 1], $rule->reminder_offsets_days);
        $this->assertSame(3, $rule->max_reminders);
        $this->assertSame(14, $rule->escalate_after_days);
    }

    public function test_edit_chase_rule_persists_a_change_for_authorized_role(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Attorney);
        $rule = $this->runWithFirmContext($firm, fn () => DocumentChaseRule::factory()->forFirm($firm)->create(['name' => 'Original']));

        $this->runWithFirmContext($firm, function () use ($rule): void {
            $test = Livewire::test(EditDocumentChaseRule::class, ['record' => $rule->getRouteKey()]);
            $test->fillForm(['name' => 'Updated', 'status' => DocumentChaseRuleStatus::Paused->value]);
            $test->call('save');
            $test->assertHasNoFormErrors();
        });

        $fresh = $this->runWithFirmContext($firm, fn () => DocumentChaseRule::query()->find($rule->id));
        $this->assertSame('Updated', $fresh->name);
        $this->assertSame(DocumentChaseRuleStatus::Paused, $fresh->status);
    }

    // ------------------------------------------------------------
    // 4. Event log is genuinely read-only, and no "send now" action
    //    exists anywhere in this module
    // ------------------------------------------------------------

    public function test_chase_events_relation_manager_renders_and_exposes_no_mutating_actions(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $rule = $this->runWithFirmContext($firm, fn () => DocumentChaseRule::factory()->forFirm($firm)->create());
        $event = $this->runWithFirmContext($firm, fn () => DocumentChaseEvent::factory()->forItem(
            DocumentRequestItem::factory()->create(),
            $firm,
            $rule,
        )->create());

        $this->runWithFirmContext($firm, function () use ($rule, $event): void {
            $test = Livewire::test(ChaseEventsRelationManager::class, [
                'ownerRecord' => $rule,
                'pageClass' => ViewDocumentChaseRule::class,
            ]);
            $test->assertOk();
            $test->assertCanSeeTableRecords([$event]);
        });

        $source = file_get_contents(app_path('Filament/Firm/Resources/DocumentChaseRuleResource/RelationManagers/ChaseEventsRelationManager.php'));
        $this->assertIsString($source);
        $this->assertStringContainsString('->headerActions([])', $source);
        $this->assertStringContainsString('->recordActions([])', $source);
        $this->assertStringContainsString('->toolbarActions([])', $source);
    }

    public function test_no_send_now_or_dispatch_action_exists_anywhere_in_the_document_chase_module(): void
    {
        $directory = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path('Filament/Firm/Resources/DocumentChaseRuleResource'), \FilesystemIterator::SKIP_DOTS)
        );

        $files = [app_path('Filament/Firm/Resources/DocumentChaseRuleResource.php')];
        foreach ($directory as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        $this->assertNotEmpty($files);

        foreach ($files as $file) {
            $source = file_get_contents($file);
            $this->assertIsString($source);
            $this->assertStringNotContainsString('checkAndLog', $source, "{$file} must never call DocumentChaseService::checkAndLog() — no scheduler exists to make eligibility checks meaningful outside a test, and no UI action may simulate one.");
            $this->assertStringNotContainsString('sendNow', $source);
            $this->assertStringNotContainsString('SendReminder', $source);
            $this->assertStringNotContainsString('DispatchReminder', $source);
        }
    }

    // ------------------------------------------------------------
    // 5. Small RLS regression checklist (a/b/c/d)
    // ------------------------------------------------------------

    /** (a) a firm user can access its own DocumentChaseRule records. */
    public function test_a_firm_user_can_access_its_own_chase_rules(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $rule = $this->runWithFirmContext($firm, fn () => DocumentChaseRule::factory()->forFirm($firm)->create());

        $response = $this->runWithFirmContext($firm, fn () => $this->get(DocumentChaseRuleResource::getUrl('view', ['record' => $rule])));

        $response->assertSuccessful();
    }

    /** (b) a foreign firm's chase rule is not returned by the list/query. */
    public function test_list_page_shows_only_this_firms_chase_rules(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $ruleA = $this->runWithFirmContext($firmA, fn () => DocumentChaseRule::factory()->forFirm($firmA)->create());
        $ruleB = $this->runWithFirmContext($firmB, fn () => DocumentChaseRule::factory()->forFirm($firmB)->create());

        $test = $this->runWithFirmContext($firmA, fn () => Livewire::test(ListDocumentChaseRules::class));

        $test->assertSuccessful();
        $test->assertCanSeeTableRecords([$ruleA]);
        $test->assertCanNotSeeTableRecords([$ruleB]);
    }

    public function test_real_rls_proof_a_raw_query_under_firm_a_context_cannot_read_firm_bs_chase_rule_row(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $ruleA = $this->runWithFirmContext($firmA, fn () => DocumentChaseRule::factory()->forFirm($firmA)->create());
        $ruleB = $this->runWithFirmContext($firmB, fn () => DocumentChaseRule::factory()->forFirm($firmB)->create());

        $visibleIds = $this->runWithFirmContext($firmA, fn () => DB::table('document_chase_rules')->pluck('id')->all());

        $this->assertContains($ruleA->id, $visibleIds);
        $this->assertNotContains($ruleB->id, $visibleIds, "Firm A's session must never read Firm B's chase rule row.");
    }

    /** (d) direct navigation to a foreign record's URL is blocked. */
    public function test_direct_url_guess_of_another_firms_chase_rule_never_succeeds(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $ruleB = $this->runWithFirmContext($firmB, fn () => DocumentChaseRule::factory()->forFirm($firmB)->create());

        $response = $this->runWithFirmContext($firmA, fn () => $this->get(DocumentChaseRuleResource::getUrl('view', ['record' => $ruleB])));

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
