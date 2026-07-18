<?php

namespace App\Services;

use App\Enums\DocumentTemplateContentStatus;
use App\Enums\GeneratedDocumentStatus;
use App\Models\Client;
use App\Models\DocumentTemplateVersion;
use App\Models\FirmUser;
use App\Models\GeneratedDocument;
use App\Models\Matter;
use App\ValueObjects\DocumentGenerationResult;

/**
 * DocumentGenerationService — the only place a GeneratedDocument is
 * created. Deliberately has NO dependency on any email/notification
 * class anywhere in its constructor — there is no code path from
 * generation to client-facing delivery (mirrors Phase 9's
 * EmailSyncService "no dispatch dependency" discipline). Rendering is
 * simulated only: simulated_storage_path is a descriptive string that
 * nothing ever writes to — no real PDF/DOCX binary is produced.
 * used_sample_content is set here from the template version's CURRENT
 * content_status at generation time; DocumentReviewService::approve()
 * re-checks it live rather than trusting this snapshot.
 *
 * Section 39A-6 Wave 6: generated_documents is now FORCE RLS
 * protected. Only the GeneratedDocument::create() call below is
 * wrapped in runWithFirmContext($firmId, ...) — this is a
 * single-table write with no paired table write in the same call.
 */
class DocumentGenerationService
{
    public function __construct(private readonly DeterministicFieldResolutionService $resolver)
    {
    }

    public function generate(
        DocumentTemplateVersion $version,
        FirmUser $actor,
        int $firmId,
        ?Matter $matter = null,
        ?Client $client = null,
    ): DocumentGenerationResult {
        if (! $version->isActive()) {
            throw new \RuntimeException(
                "Cannot generate a document from document_template_version [id={$version->id}] with status '{$version->status->value}' — only an Active version may be used."
            );
        }

        $usedSampleContent = $version->content_status === DocumentTemplateContentStatus::SampleOnly;

        $resolvedMergeValues = $this->resolveMergeFields($version, $matter, $client);

        $simulatedStoragePath = sprintf(
            'generated-documents/firm-%d/template-%s/%s.pdf',
            $firmId,
            $version->uuid,
            (string) \Illuminate\Support\Str::uuid()
        );

        $document = (new TenantContextService())->runWithFirmContext($firmId, fn () => GeneratedDocument::create([
            'firm_id' => $firmId,
            'matter_id' => $matter?->id,
            'client_id' => $client?->id,
            'document_template_version_id' => $version->id,
            'status' => GeneratedDocumentStatus::Draft,
            'simulated_storage_path' => $simulatedStoragePath,
            'used_sample_content' => $usedSampleContent,
            'generated_by_firm_user_id' => $actor->id,
        ]));

        return new DocumentGenerationResult(
            generatedDocumentId: $document->id,
            status: $document->status,
            simulatedStoragePath: $simulatedStoragePath,
            usedSampleContent: $usedSampleContent,
            resolvedMergeValues: $resolvedMergeValues,
        );
    }

    /**
     * Resolves each entry in the version's merge_fields_schema
     * deterministically via DeterministicFieldResolutionService.
     * Schema entries look like:
     *   ['token' => 'client_name', 'source_entity' => 'client',
     *    'source_path' => 'client.display_name', 'transform' => 'none']
     * Results are returned only in memory (see DocumentGenerationResult
     * docblock) — no real renderer exists yet to consume them, and no
     * generated_document_values table was approved for this phase.
     *
     * @return array<string, ?string>
     */
    private function resolveMergeFields(DocumentTemplateVersion $version, ?Matter $matter, ?Client $client): array
    {
        $context = ['matter' => $matter, 'client' => $client];
        $resolved = [];

        foreach ($version->merge_fields_schema as $fieldSchema) {
            $token = $fieldSchema['token'] ?? null;
            $sourceEntity = \App\Enums\FormMappingSourceEntity::tryFrom($fieldSchema['source_entity'] ?? '');
            $sourcePath = $fieldSchema['source_path'] ?? null;
            $transform = \App\Enums\FormMappingTransform::tryFrom($fieldSchema['transform'] ?? 'none') ?? \App\Enums\FormMappingTransform::None;

            if ($token === null || $sourceEntity === null || $sourcePath === null) {
                continue;
            }

            $value = $this->resolver->resolve($sourceEntity, $sourcePath, $context);
            $resolved[$token] = $this->resolver->applyTransform($value, $transform);
        }

        return $resolved;
    }
}
