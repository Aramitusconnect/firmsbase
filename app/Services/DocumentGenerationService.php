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
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * DocumentGenerationService — the only place a GeneratedDocument is
 * created. Deliberately has NO dependency on any email/notification
 * class anywhere in its constructor — there is no code path from
 * generation to client-facing delivery (mirrors Phase 9's
 * EmailSyncService "no dispatch dependency" discipline).
 *
 * Rendering is real: the template version's body_template ({{token}}
 * merge text) has its tokens substituted with the deterministically
 * resolved merge values (HTML-escaped, since a resolved value — e.g. a
 * client display name — is data, not trusted markup, while the
 * template body itself is attorney/platform-admin-approved trusted
 * content), wrapped as minimal HTML if it isn't already, and rendered
 * to a PDF via Dompdf (`barryvdh/laravel-dompdf`) — the exact same
 * package/usage pattern already proven by
 * FinancialEvidenceReportsPanel::exportPdf(). The resulting bytes are
 * written via Storage::disk(...) to a firm/matter-scoped path mirroring
 * DocumentsRelationManager's real-upload convention, and their sha256
 * is durably recorded via DocumentHashService::recordForGeneratedDocument()
 * — the wiring that makes the downstream e-signature certificate flow
 * (SignatureCertificateService::generate()) reachable in production.
 *
 * simulated_storage_path is kept, unchanged in format, purely for
 * backward compatibility — other code/tests still read its exact
 * shape. storage_disk/storage_path are the real location of the
 * rendered PDF.
 *
 * used_sample_content is set here from the template version's CURRENT
 * content_status at generation time; DocumentReviewService::approve()
 * re-checks it live rather than trusting this snapshot.
 *
 * Section 39A-6 Wave 6: generated_documents is now FORCE RLS
 * protected. Only the GeneratedDocument::create() call below is
 * wrapped in runWithFirmContext($firmId, ...) — this is a
 * single-table write with no paired table write in the same call.
 * DocumentHashService::recordForGeneratedDocument() below opens its
 * own independent, non-nested runWithFirmContext() wrap for the
 * document_hashes write, exactly per that service's own documented
 * pattern.
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
            (string) Str::uuid()
        );

        $pdfBytes = Pdf::loadHTML($this->renderHtml($version->body_template, $resolvedMergeValues))->output();

        $storageDisk = 'local';
        $storagePath = sprintf(
            'generated-documents/%d/%s/%s.pdf',
            $firmId,
            $matter?->id ?? 'unscoped',
            (string) Str::uuid7()
        );
        Storage::disk($storageDisk)->put($storagePath, $pdfBytes);
        $fileHash = hash('sha256', $pdfBytes);

        $document = (new TenantContextService())->runWithFirmContext($firmId, fn () => GeneratedDocument::create([
            'firm_id' => $firmId,
            'matter_id' => $matter?->id,
            'client_id' => $client?->id,
            'document_template_version_id' => $version->id,
            'status' => GeneratedDocumentStatus::Draft,
            'simulated_storage_path' => $simulatedStoragePath,
            'storage_disk' => $storageDisk,
            'storage_path' => $storagePath,
            'used_sample_content' => $usedSampleContent,
            'generated_by_firm_user_id' => $actor->id,
        ]));

        (new DocumentHashService())->recordForGeneratedDocument($document, $fileHash, $actor);

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
     * Results are returned in memory AND consumed by renderHtml() below
     * to substitute the template body's {{token}} placeholders before
     * Dompdf rendering — no generated_document_values table was
     * approved for this phase, so the resolved values themselves are
     * never separately persisted, only the rendered PDF they produced.
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

    /**
     * Substitutes each {{token}} placeholder in the (trusted,
     * attorney/platform-admin-approved) template body with its
     * resolved value, HTML-escaping only the substituted VALUE — the
     * template body itself is left untouched, since it may legitimately
     * already contain markup. Any token with no resolved entry (e.g.
     * unresolvable/missing source data) is left as literal {{token}}
     * text, matching the existing "unresolvable field" behavior of
     * resolveMergeFields(). Wraps the result in a minimal HTML document
     * only if the body doesn't already look like one, since
     * body_template today is plain merge text, not markup.
     *
     * @param  array<string, ?string>  $resolvedMergeValues
     */
    private function renderHtml(string $bodyTemplate, array $resolvedMergeValues): string
    {
        $substituted = $bodyTemplate;

        foreach ($resolvedMergeValues as $token => $value) {
            $substituted = str_replace('{{'.$token.'}}', e($value ?? ''), $substituted);
        }

        if (! str_contains(mb_strtolower($substituted), '<html')) {
            $substituted = '<html><body>'.nl2br($substituted).'</body></html>';
        }

        return $substituted;
    }
}
