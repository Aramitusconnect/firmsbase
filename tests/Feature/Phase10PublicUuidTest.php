<?php

namespace Tests\Feature;

use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateVersion;
use App\Models\FormDraft;
use App\Models\FormTemplate;
use App\Models\FormTemplateVersion;
use App\Models\GeneratedDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Confirms the exact 6 approved Phase 10 uuid models (FormTemplate,
 * FormTemplateVersion, FormDraft, DocumentTemplate,
 * DocumentTemplateVersion, GeneratedDocument) and that the remaining 8
 * tables (fields/rules/values/events/missing-data/checklist/watch —
 * join, audit, or child rows scoped transitively through a parent) do
 * not carry a public uuid.
 */
class Phase10PublicUuidTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('uuidModelProvider')]
    public function test_model_has_a_public_uuid(string $modelClass): void
    {
        $instance = $modelClass::factory()->create();

        $this->assertNotNull($instance->uuid);
    }

    public static function uuidModelProvider(): array
    {
        return [
            [FormTemplate::class],
            [FormTemplateVersion::class],
            [FormDraft::class],
            [DocumentTemplate::class],
            [DocumentTemplateVersion::class],
            [GeneratedDocument::class],
        ];
    }

    #[DataProvider('noUuidTableProvider')]
    public function test_table_has_no_uuid_column(string $table): void
    {
        $columns = Schema::getColumnListing($table);

        $this->assertNotContains('uuid', $columns);
    }

    public static function noUuidTableProvider(): array
    {
        return [
            ['form_fields'],
            ['form_mapping_rules'],
            ['form_draft_values'],
            ['form_review_events'],
            ['form_missing_data_items'],
            ['form_review_checklist_items'],
            ['form_edition_watch_items'],
            ['generated_document_events'],
        ];
    }
}
