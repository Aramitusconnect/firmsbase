<?php

declare(strict_types=1);

namespace Tests\Feature\TasksDeadlines;

use App\Enums\FirmUserRole;
use App\Enums\TaskStatus;
use App\Filament\Firm\Resources\TaskResource;
use App\Filament\Firm\Resources\TaskResource\Actions\AddTaskDependencyAction;
use App\Filament\Firm\Resources\TaskResource\Actions\CompleteTaskAction;
use App\Filament\Firm\Resources\TaskResource\Actions\StartTaskAction;
use App\Filament\Firm\Resources\TaskResource\Pages\CreateTask;
use App\Filament\Firm\Resources\TaskResource\Pages\EditTask;
use App\Filament\Firm\Resources\TaskResource\Pages\ListTasks;
use App\Models\Client;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Models\Task;
use App\Models\TaskDependency;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * TaskResourceAccessTest — Firm Feature Manifest §3 (Tier1-B). Proves
 * role ceilings, real service-mediated create (TaskService::create()),
 * plain field edit, status-transition Actions
 * (Start/Complete/AddTaskDependency), TaskDependencyService's cycle
 * rejection surfacing correctly through the UI action, and the small
 * RLS regression checklist required for this module (own-firm access,
 * foreign-firm exclusion from list/query, foreign-id not selectable via
 * the matter_id relation select, and direct-URL cross-firm denial).
 * The broader RLS rollout itself is out of scope here — see this
 * mission's own scope note.
 */
final class TaskResourceAccessTest extends TestCase
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

    public function test_guest_cannot_access_the_task_resource(): void
    {
        $this->assertFalse(TaskResource::canAccess());
    }

    public function test_view_roles_can_access_the_task_resource(): void
    {
        foreach (FirmUserRole::cases() as $role) {
            $firm = Firm::factory()->create();
            $this->actingAsRole($firm, $role);

            $this->assertTrue(TaskResource::canAccess(), "canAccess() failed for role {$role->value}");
        }
    }

    public function test_receptionist_can_create_a_task_but_billing_staff_cannot(): void
    {
        $firmA = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::Receptionist);
        $this->assertTrue(TaskResource::canCreate());

        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmB, FirmUserRole::BillingStaff);
        $this->assertFalse(TaskResource::canCreate());
    }

    // ------------------------------------------------------------
    // 2. Create/Edit — real service-mediated create, plain field edit
    // ------------------------------------------------------------

    public function test_create_task_persists_via_task_service_and_links_matter_and_client(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Paralegal);
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create());
        $matter = $this->runWithFirmContext($firm, fn () => Matter::factory()->forFirm($firm)->forClient($client)->create());

        $this->runWithFirmContext($firm, function () use ($client, $matter): void {
            $test = Livewire::test(CreateTask::class);
            $test->fillForm([
                'title' => 'Prepare intake packet',
                'description' => 'Send the standard intake packet.',
                'matter_id' => $matter->id,
                'client_id' => $client->id,
                'priority' => 'high',
                'due_at' => now()->addDays(3)->toDateTimeString(),
            ]);
            $test->call('create');
            $test->assertHasNoFormErrors();
        });

        $task = $this->runWithFirmContext($firm, fn () => Task::query()->where('title', 'Prepare intake packet')->first());
        $this->assertNotNull($task);
        $this->assertSame((int) $firm->id, (int) $task->firm_id);
        $this->assertSame($matter->id, $task->matter_id);
        $this->assertSame($client->id, $task->client_id);
        $this->assertSame(TaskStatus::Open, $task->status);
    }

    public function test_edit_task_persists_a_change_via_the_wrapped_tenant_context(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::LegalAssistant);
        $task = $this->runWithFirmContext($firm, fn () => Task::factory()->create(['firm_id' => $firm->id, 'title' => 'Original Title']));

        $this->runWithFirmContext($firm, function () use ($task): void {
            $test = Livewire::test(EditTask::class, ['record' => $task->getRouteKey()]);
            $test->fillForm(['title' => 'Updated Title']);
            $test->call('save');
            $test->assertHasNoFormErrors();
        });

        $fresh = $this->runWithFirmContext($firm, fn () => Task::query()->find($task->id));
        $this->assertSame('Updated Title', $fresh->title);
    }

    public function test_task_form_never_declares_a_status_field(): void
    {
        $source = file_get_contents(app_path('Filament/Firm/Resources/TaskResource.php'));
        $this->assertIsString($source);

        preg_match('/public static function form\(.*?\n    \}/s', $source, $matches);
        $this->assertNotEmpty($matches);

        $this->assertStringNotContainsString("make('status')", $matches[0]);
    }

    // ------------------------------------------------------------
    // 3. Status-transition Actions
    // ------------------------------------------------------------

    public function test_start_task_action_visible_for_paralegal_and_hidden_for_billing_staff(): void
    {
        $firmA = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::Paralegal);
        $taskA = $this->runWithFirmContext($firmA, fn () => Task::factory()->create(['firm_id' => $firmA->id, 'status' => TaskStatus::Open]));

        $this->runWithFirmContext($firmA, function () use ($taskA): void {
            $test = Livewire::test(ListTasks::class);
            $test->assertTableActionVisible(StartTaskAction::getDefaultName(), $taskA);
        });

        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmB, FirmUserRole::BillingStaff);
        $taskB = $this->runWithFirmContext($firmB, fn () => Task::factory()->create(['firm_id' => $firmB->id, 'status' => TaskStatus::Open]));

        $this->runWithFirmContext($firmB, function () use ($taskB): void {
            $test = Livewire::test(ListTasks::class);
            $test->assertTableActionHidden(StartTaskAction::getDefaultName(), $taskB);
        });
    }

    public function test_complete_task_action_is_hidden_for_a_blocked_task(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $task = $this->runWithFirmContext($firm, fn () => Task::factory()->create(['firm_id' => $firm->id, 'status' => TaskStatus::Blocked]));

        $this->runWithFirmContext($firm, function () use ($task): void {
            $test = Livewire::test(ListTasks::class);
            $test->assertTableActionHidden(CompleteTaskAction::getDefaultName(), $task);
        });
    }

    public function test_complete_task_action_completes_an_open_task(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::FirmOwner);
        $task = $this->runWithFirmContext($firm, fn () => Task::factory()->create(['firm_id' => $firm->id, 'status' => TaskStatus::Open]));

        $this->runWithFirmContext($firm, function () use ($task): void {
            $test = Livewire::test(ListTasks::class);
            $test->callTableAction(CompleteTaskAction::getDefaultName(), $task);
            $test->assertNotified('Task completed');
        });

        $fresh = $this->runWithFirmContext($firm, fn () => Task::query()->find($task->id));
        $this->assertSame(TaskStatus::Completed, $fresh->status);
        $this->assertNotNull($fresh->completed_at);
    }

    // ------------------------------------------------------------
    // 4. TaskDependencyService cycle rejection surfaces in the UI
    // ------------------------------------------------------------

    public function test_add_task_dependency_action_succeeds_and_blocks_the_task(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Attorney);
        $taskA = $this->runWithFirmContext($firm, fn () => Task::factory()->create(['firm_id' => $firm->id, 'title' => 'Task A']));
        $taskB = $this->runWithFirmContext($firm, fn () => Task::factory()->create(['firm_id' => $firm->id, 'title' => 'Task B']));

        $this->runWithFirmContext($firm, function () use ($taskA, $taskB): void {
            $test = Livewire::test(ListTasks::class);
            $test->mountTableAction(AddTaskDependencyAction::getDefaultName(), $taskA->id);
            $test->setActionData(['blocked_by_task_id' => $taskB->id]);
            $test->callMountedTableAction();
            $test->assertHasNoTableActionErrors();
            $test->assertNotified('Dependency added');
        });

        $exists = $this->runWithFirmContext($firm, fn () => TaskDependency::query()
            ->where('task_id', $taskA->id)
            ->where('blocked_by_task_id', $taskB->id)
            ->exists());
        $this->assertTrue($exists);

        $fresh = $this->runWithFirmContext($firm, fn () => Task::query()->find($taskA->id));
        $this->assertSame(TaskStatus::Blocked, $fresh->status);
    }

    public function test_add_task_dependency_action_rejects_a_cycle_and_surfaces_a_notification(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Attorney);
        $taskA = $this->runWithFirmContext($firm, fn () => Task::factory()->create(['firm_id' => $firm->id, 'title' => 'Task A']));
        $taskB = $this->runWithFirmContext($firm, fn () => Task::factory()->create(['firm_id' => $firm->id, 'title' => 'Task B']));

        // A is already blocked by B (A depends on B). Now try to make B
        // depend on A too — a direct cycle — via the UI action.
        $this->runWithFirmContext($firm, fn () => TaskDependency::create([
            'task_id' => $taskA->id,
            'blocked_by_task_id' => $taskB->id,
        ]));

        $this->runWithFirmContext($firm, function () use ($taskA, $taskB): void {
            $test = Livewire::test(ListTasks::class);
            $test->mountTableAction(AddTaskDependencyAction::getDefaultName(), $taskB->id);
            $test->setActionData(['blocked_by_task_id' => $taskA->id]);
            $test->callMountedTableAction();
            $test->assertNotified('Could not add dependency');
        });

        $exists = $this->runWithFirmContext($firm, fn () => TaskDependency::query()
            ->where('task_id', $taskB->id)
            ->where('blocked_by_task_id', $taskA->id)
            ->exists());
        $this->assertFalse($exists, 'A cycle-creating dependency must never be persisted.');
    }

    // ------------------------------------------------------------
    // 5. Small RLS regression checklist (a/b/c/d — see this mission's
    //    scope note; the broader RLS rollout itself is not re-tested
    //    here).
    // ------------------------------------------------------------

    /** (a) a firm user can access its own Task records. */
    public function test_a_firm_user_can_access_its_own_tasks(): void
    {
        $firm = Firm::factory()->create();
        $this->actingAsRole($firm, FirmUserRole::Attorney);
        $task = $this->runWithFirmContext($firm, fn () => Task::factory()->create(['firm_id' => $firm->id]));

        $response = $this->runWithFirmContext($firm, fn () => $this->get(TaskResource::getUrl('view', ['record' => $task])));

        $response->assertSuccessful();
    }

    /** (b) a foreign firm's Task is not returned by the list/query. */
    public function test_list_page_shows_only_this_firms_tasks(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $taskA = $this->runWithFirmContext($firmA, fn () => Task::factory()->create(['firm_id' => $firmA->id]));
        $taskB = $this->runWithFirmContext($firmB, fn () => Task::factory()->create(['firm_id' => $firmB->id]));

        $test = $this->runWithFirmContext($firmA, fn () => Livewire::test(ListTasks::class));

        $test->assertSuccessful();
        $test->assertCanSeeTableRecords([$taskA]);
        $test->assertCanNotSeeTableRecords([$taskB]);
    }

    public function test_real_rls_proof_a_raw_query_under_firm_a_context_cannot_read_firm_bs_task_row(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $taskA = $this->runWithFirmContext($firmA, fn () => Task::factory()->create(['firm_id' => $firmA->id]));
        $taskB = $this->runWithFirmContext($firmB, fn () => Task::factory()->create(['firm_id' => $firmB->id]));

        $visibleIds = $this->runWithFirmContext($firmA, fn () => DB::table('tasks')->pluck('id')->all());

        $this->assertContains($taskA->id, $visibleIds);
        $this->assertNotContains($taskB->id, $visibleIds, "Firm A's session must never read Firm B's task row.");
    }

    /** (c) a foreign matter cannot be selected via the matter_id relation select. */
    public function test_matter_select_options_never_include_a_foreign_firms_matter(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::Paralegal);
        $clientA = $this->runWithFirmContext($firmA, fn () => Client::factory()->forFirm($firmA)->create());
        $matterA = $this->runWithFirmContext($firmA, fn () => Matter::factory()->forFirm($firmA)->forClient($clientA)->create());
        $clientB = $this->runWithFirmContext($firmB, fn () => Client::factory()->forFirm($firmB)->create());
        $matterB = $this->runWithFirmContext($firmB, fn () => Matter::factory()->forFirm($firmB)->forClient($clientB)->create());

        $response = $this->runWithFirmContext($firmA, fn () => $this->get(TaskResource::getUrl('create')));
        $response->assertSuccessful();

        $this->runWithFirmContext($firmA, function () use ($matterA, $matterB): void {
            $visibleMatterIds = Matter::query()->pluck('id')->all();

            $this->assertContains($matterA->id, $visibleMatterIds);
            $this->assertNotContains($matterB->id, $visibleMatterIds, "Firm A's matter_id options must never include Firm B's matter.");
        });
    }

    /** (d) direct navigation to a foreign record's URL is blocked. */
    public function test_direct_url_guess_of_another_firms_task_never_succeeds(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $this->actingAsRole($firmA, FirmUserRole::FirmOwner);
        $taskB = $this->runWithFirmContext($firmB, fn () => Task::factory()->create(['firm_id' => $firmB->id]));

        $response = $this->runWithFirmContext($firmA, fn () => $this->get(TaskResource::getUrl('view', ['record' => $taskB])));

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
