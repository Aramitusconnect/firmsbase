<?php

namespace Tests\Feature\Forms\Watch;

use App\Enums\FormEditionWatchStatus;
use App\Models\FormTemplate;
use App\Models\PlatformAdmin;
use App\Services\FormEditionWatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FormEditionWatchServiceTest extends TestCase
{
    use RefreshDatabase;

    private FormEditionWatchService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FormEditionWatchService();
    }

    public function test_start_watching_creates_an_item_with_watching_status(): void
    {
        $template = FormTemplate::factory()->create();
        $admin = PlatformAdmin::factory()->create();

        $item = $this->service->startWatching($template, $admin);

        $this->assertSame(FormEditionWatchStatus::Watching, $item->watch_status);
        $this->assertSame($admin->id, $item->created_by_platform_admin_id);
    }

    public function test_full_watch_lifecycle(): void
    {
        $template = FormTemplate::factory()->create();
        $admin = PlatformAdmin::factory()->create();
        $item = $this->service->startWatching($template, $admin);

        $item = $this->service->markNewEditionDetected($item, '01/20/2026', 'USCIS posted a new edition date');
        $this->assertSame(FormEditionWatchStatus::NewEditionDetected, $item->watch_status);
        $this->assertSame('01/20/2026', $item->detected_edition_date);

        $item = $this->service->markInReview($item);
        $this->assertSame(FormEditionWatchStatus::InReview, $item->watch_status);

        $item = $this->service->markUpdated($item);
        $this->assertSame(FormEditionWatchStatus::Updated, $item->watch_status);
    }

    public function test_mark_no_action_needed(): void
    {
        $template = FormTemplate::factory()->create();
        $admin = PlatformAdmin::factory()->create();
        $item = $this->service->startWatching($template, $admin);

        $item = $this->service->markNoActionNeeded($item);

        $this->assertSame(FormEditionWatchStatus::NoActionNeeded, $item->watch_status);
    }

    public function test_watch_items_have_no_firm_id_column(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('form_edition_watch_items', 'firm_id'));
    }
}
