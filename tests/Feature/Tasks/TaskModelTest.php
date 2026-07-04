<?php

namespace Tests\Feature\Tasks;

use App\Enums\TaskStatus;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TaskModelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array<int, TaskStatus|bool>>
     */
    public static function openForWorkProvider(): array
    {
        return [
            'open' => [TaskStatus::Open, true],
            'in_progress' => [TaskStatus::InProgress, true],
            'overdue' => [TaskStatus::Overdue, true],
            'blocked' => [TaskStatus::Blocked, false],
            'completed' => [TaskStatus::Completed, false],
            'cancelled' => [TaskStatus::Cancelled, false],
        ];
    }

    #[DataProvider('openForWorkProvider')]
    public function test_is_open_for_work(TaskStatus $status, bool $expected): void
    {
        $task = Task::factory()->create(['status' => $status]);

        $this->assertSame($expected, $task->isOpenForWork());
    }

    public function test_no_uuid_column_is_carried_on_the_model(): void
    {
        $task = Task::factory()->create();

        $this->assertArrayNotHasKey('uuid', $task->getAttributes());
    }
}
