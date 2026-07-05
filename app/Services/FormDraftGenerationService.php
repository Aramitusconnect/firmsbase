<?php

namespace App\Services;

use App\Enums\FormDraftStatus;
use App\Enums\FormDraftValueSource;
use App\Enums\FormMappingContentStatus;
use App\Models\Client;
use App\Models\FirmUser;
use App\Models\FormDraft;
use App\Models\FormDraftValue;
use App\Models\FormTemplateVersion;
use App\Models\Matter;
use App\ValueObjects\FormDraftGenerationResult;

/**
 * FormDraftGenerationService — the only place a FormDraft is created.
 * Blocks generation from a Retired (or otherwise non-Active) version
 * (project rule: "drafts must not be generated from retired form
 * versions"). Resolves every field deterministically via
 * DeterministicFieldResolutionService — no AI anywhere in this path.
 * used_sample_mapping is computed here as a fast/cached flag; the
 * AUTHORITATIVE check happens again, live, in
 * FormReviewService::approve().
 */
class FormDraftGenerationService
{
    public function __construct(
        private readonly DeterministicFieldResolutionService $resolver,
        private readonly FormMissingDataDetectionService $missingDataDetectionService,
    ) {
    }

    public function generate(Matter $matter, FormTemplateVersion $version, FirmUser $actor, ?Client $client = null): FormDraftGenerationResult
    {
        if (! $version->isActive()) {
            throw new \RuntimeException(
                "Cannot generate a draft from form_template_version [id={$version->id}] with status '{$version->status->value}' — only an Active version may be used."
            );
        }

        $draft = FormDraft::create([
            'firm_id' => $matter->firm_id,
            'matter_id' => $matter->id,
            'client_id' => $client?->id,
            'form_template_version_id' => $version->id,
            'status' => FormDraftStatus::Draft,
            'used_sample_mapping' => false,
            'generated_by_firm_user_id' => $actor->id,
        ]);

        $context = [
            'client' => $client,
            'matter' => $matter,
        ];

        $usedSampleMapping = false;
        $valuesGenerated = 0;

        foreach ($version->mappingRules()->with('formField')->get() as $rule) {
            $resolved = $this->resolver->resolve($rule->source_entity, $rule->source_path, $context);
            $resolved = $this->resolver->applyTransform($resolved, $rule->transform);

            FormDraftValue::create([
                'form_draft_id' => $draft->id,
                'form_field_id' => $rule->form_field_id,
                'form_mapping_rule_id' => $rule->id,
                'value' => $resolved,
                'source' => $resolved === null ? FormDraftValueSource::Missing : FormDraftValueSource::Mapped,
            ]);

            $valuesGenerated++;

            if ($rule->content_status === FormMappingContentStatus::SampleOnly) {
                $usedSampleMapping = true;
            }
        }

        $draft->update(['used_sample_mapping' => $usedSampleMapping]);

        $missingResult = $this->missingDataDetectionService->scan($draft->fresh());

        if (! $missingResult->isComplete()) {
            $draft->update(['status' => FormDraftStatus::NeedsData]);
        }

        return new FormDraftGenerationResult(
            formDraftId: $draft->id,
            valuesGenerated: $valuesGenerated,
            missingRequiredCount: count($missingResult->missingFieldCodes),
            usedSampleMapping: $usedSampleMapping,
        );
    }
}
