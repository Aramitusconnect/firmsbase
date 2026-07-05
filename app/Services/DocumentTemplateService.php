<?php

namespace App\Services;

use App\Enums\DocumentTemplateCategory;
use App\Enums\DocumentTemplateContentStatus;
use App\Enums\DocumentTemplateVersionStatus;
use App\Enums\FirmUserRole;
use App\Models\DocumentTemplate;
use App\Models\DocumentTemplateVersion;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\PlatformAdmin;

/**
 * DocumentTemplateService — dual-actor invariant enforced here, not
 * left to the caller: a firm-specific template (firm_id set) must be
 * created/content-approved by a FirmUser of that SAME firm (role
 * FirmOwner/Attorney for content approval); a global template (firm_id
 * null) must be created/content-approved by a PlatformAdmin. Exactly
 * one actor type is ever accepted per call — never both, never
 * neither. No AI actor type exists anywhere, so "no AI approval of
 * template content" is structural, not a policy note.
 */
class DocumentTemplateService
{
    private const CONTENT_APPROVAL_ROLES = [
        FirmUserRole::FirmOwner,
        FirmUserRole::Attorney,
    ];

    public function createGlobal(string $templateCode, string $name, DocumentTemplateCategory $category, PlatformAdmin $actor): DocumentTemplate
    {
        return DocumentTemplate::create([
            'firm_id' => null,
            'template_code' => $templateCode,
            'name' => $name,
            'category' => $category,
            'created_by_platform_admin_id' => $actor->id,
        ]);
    }

    public function createFirmSpecific(Firm $firm, string $templateCode, string $name, DocumentTemplateCategory $category, FirmUser $actor): DocumentTemplate
    {
        if ($actor->firm_id !== $firm->id) {
            throw new \RuntimeException('Actor does not belong to the firm this template is being created for.');
        }

        return DocumentTemplate::create([
            'firm_id' => $firm->id,
            'template_code' => $templateCode,
            'name' => $name,
            'category' => $category,
            'created_by_firm_user_id' => $actor->id,
        ]);
    }

    public function createVersion(DocumentTemplate $template, string $versionLabel, array $mergeFieldsSchema, string $bodyTemplate): DocumentTemplateVersion
    {
        return DocumentTemplateVersion::create([
            'document_template_id' => $template->id,
            'version_label' => $versionLabel,
            'status' => DocumentTemplateVersionStatus::Draft,
            'merge_fields_schema' => $mergeFieldsSchema,
            'body_template' => $bodyTemplate,
            'content_status' => DocumentTemplateContentStatus::SampleOnly,
        ]);
    }

    public function activate(DocumentTemplateVersion $version): DocumentTemplateVersion
    {
        $version->update(['status' => DocumentTemplateVersionStatus::Active]);

        return $version->fresh();
    }

    public function retire(DocumentTemplateVersion $version): DocumentTemplateVersion
    {
        $version->update(['status' => DocumentTemplateVersionStatus::Retired]);

        return $version->fresh();
    }

    /**
     * approveContent() — global template content requires a
     * PlatformAdmin actor; firm-specific template content requires a
     * FirmOwner/Attorney of the SAME firm that owns the template.
     */
    public function approveContent(DocumentTemplateVersion $version, PlatformAdmin|FirmUser $actor): DocumentTemplateVersion
    {
        $template = $version->documentTemplate;

        if ($template->isGlobalDefault()) {
            if (! $actor instanceof PlatformAdmin) {
                throw new \RuntimeException('Only a PlatformAdmin may approve content for a global document template.');
            }
        } else {
            if (! $actor instanceof FirmUser) {
                throw new \RuntimeException('Only a FirmOwner or Attorney of the owning firm may approve content for a firm-specific document template.');
            }

            if ($actor->firm_id !== $template->firm_id) {
                throw new \RuntimeException('Actor does not belong to the firm that owns this document template.');
            }

            if (! in_array($actor->role, self::CONTENT_APPROVAL_ROLES, true)) {
                throw new \RuntimeException('Actor role is not permitted to approve document template content.');
            }
        }

        $version->update(['content_status' => DocumentTemplateContentStatus::ReviewedApproved]);

        return $version->fresh();
    }
}
