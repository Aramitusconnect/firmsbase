<?php

namespace Tests\Feature\Announcements;

use App\Enums\ReleaseNoteStatus;
use App\Services\ReleaseNoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReleaseNoteServiceTest extends TestCase
{
    use RefreshDatabase;

    private ReleaseNoteService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ReleaseNoteService();
    }

    public function test_release_notes_are_platform_level_with_no_firm_org_or_plan_column(): void
    {
        $columns = Schema::getColumnListing('release_notes');

        $this->assertNotContains('firm_id', $columns);
        $this->assertNotContains('organization_id', $columns);
        $this->assertNotContains('plan_id', $columns);
    }

    public function test_create_and_publish(): void
    {
        $note = $this->service->create(['title' => 'v1.2 released', 'body' => 'Details']);
        $this->assertSame(ReleaseNoteStatus::Draft, $note->status);

        $published = $this->service->publish($note);
        $this->assertSame(ReleaseNoteStatus::Published, $published->status);
        $this->assertNotNull($published->published_at);
    }

    public function test_list_published_excludes_drafts(): void
    {
        $draft = $this->service->create(['title' => 'Draft note', 'body' => 'x']);
        $published = $this->service->create(['title' => 'Published note', 'body' => 'x']);
        $this->service->publish($published);

        $list = $this->service->listPublished();

        $this->assertTrue($list->contains('id', $published->id));
        $this->assertFalse($list->contains('id', $draft->id));
    }
}
