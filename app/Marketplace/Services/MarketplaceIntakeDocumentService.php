<?php

declare(strict_types=1);

namespace App\Marketplace\Services;

use App\Enums\DocumentScanStatus;
use App\Jobs\ScanDocumentJob;
use App\Marketplace\Models\MarketplaceIntake;
use App\Models\Document;
use App\Models\Firm;
use App\Services\Automation\DomainEventRecorderService;
use App\Services\DocumentSecurityService;
use App\Services\DocumentUploadPolicyService;
use App\Services\TenantContextService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * MarketplaceIntakeDocumentService — Mission 3 (MyAttorney Conversion
 * + AI Intake), checkpoint 7. Lets an anonymous MyAttorney prospect
 * attach a file to their own Firm-scoped MarketplaceIntake, safely,
 * before any Firm staff member has reviewed anything.
 *
 * Deliberately reuses the existing, mature document-quarantine
 * pipeline wholesale rather than building a second one:
 * DocumentUploadPolicyService (extension/size rules),
 * DocumentSecurityService::upload() (the sole Document writer,
 * status=Uploaded/scan_status=Pending), and ScanDocumentJob (the sole
 * caller of VirusScanner/applyScanResult()). This service's own job is
 * narrow: resolve the correct Firm context for an actor who has no
 * User row, generate a non-guessable storage path, and gate what an
 * anonymous visitor vs. a later Firm reviewer may ever see about the
 * result.
 *
 * $intake->firm_id is NOT NULL from creation (see MarketplaceIntake's
 * own docblock) — a real Firm always already exists by the time an
 * intake exists, so no nullable-firm_id RLS design is needed here,
 * unlike marketplace_ai_usage_events.
 */
class MarketplaceIntakeDocumentService
{
    private const STORAGE_DISK = 'local';

    public function __construct(
        private readonly DocumentUploadPolicyService $uploadPolicy = new DocumentUploadPolicyService,
        private readonly DocumentSecurityService $documentSecurity = new DocumentSecurityService(new DocumentUploadPolicyService, new DomainEventRecorderService),
        private readonly MarketplaceIntakeService $intakeService = new MarketplaceIntakeService,
        private readonly TenantContextService $tenantContext = new TenantContextService,
    ) {}

    /**
     * @throws \InvalidArgumentException if the file's extension/size is not allowed (DocumentUploadPolicyService).
     * @throws \RuntimeException if the intake can no longer accept uploads (already terminal).
     */
    public function upload(MarketplaceIntake $intake, UploadedFile $file, ?string $ipAddress = null): Document
    {
        if ($intake->status->isTerminal()) {
            throw new \RuntimeException('This intake can no longer accept document uploads.');
        }

        $firm = Firm::query()->findOrFail($intake->firm_id);

        $originalFilename = (string) $file->getClientOriginalName();
        $sizeBytes = (int) $file->getSize();

        // Fails fast, before anything touches disk, on a disallowed
        // extension/oversized file — the exact same rule every other
        // upload path in this codebase enforces (project rule: no
        // second set of upload rules).
        $this->uploadPolicy->assertUploadIsAllowed($originalFilename, $sizeBytes);

        // Never trust the visitor's own filename as a storage path —
        // a fixed, per-firm/per-intake directory plus a fresh uuid7
        // prefix, mirroring PlaidUploadFallbackPage's own established
        // convention. The original filename is preserved only as
        // Document::original_filename metadata, never as the path.
        $storagePath = sprintf(
            'marketplace-intake-uploads/%d/%d/%s-%s',
            $firm->id,
            $intake->id,
            (string) Str::uuid7(),
            $originalFilename,
        );

        Storage::disk(self::STORAGE_DISK)->putFileAs(
            dirname($storagePath),
            $file,
            basename($storagePath),
        );

        $fileHash = hash_file('sha256', $file->getRealPath()) ?: '';

        return $this->tenantContext->runWithFirmContext($firm, function () use ($firm, $intake, $originalFilename, $file, $storagePath, $fileHash, $sizeBytes, $ipAddress) {
            $document = $this->documentSecurity->upload(
                firm: $firm,
                originalFilename: $originalFilename,
                mimeType: (string) ($file->getMimeType() ?? 'application/octet-stream'),
                sizeBytes: $sizeBytes,
                storageDisk: self::STORAGE_DISK,
                storagePath: $storagePath,
                fileHash: $fileHash,
                intake: $intake,
            );

            ScanDocumentJob::dispatch($document->id, $firm->id);

            $this->intakeService->recordDocumentUploaded($firm, $intake, $document, $ipAddress);

            return $document;
        });
    }

    /**
     * The visitor's own "your uploaded files" progress view — every
     * document they attached, regardless of scan outcome, but WITHOUT
     * exposing scan_result_detail/rejected_reason (which may name a
     * real malware signature) — matching this codebase's "collapse to
     * false/generic, never disclose why" convention for anything a
     * public, unauthenticated visitor can see. A visitor who uploaded
     * a rejected file only ever learns "this file could not be
     * accepted — please try a different file."
     *
     * @return array<int, array{id: int, original_filename: string, accepted: bool, pending: bool}>
     */
    public function visitorSummary(Firm $firm, MarketplaceIntake $intake): array
    {
        $this->assertBelongsToFirm($firm, $intake);

        return $this->tenantContext->runWithFirmContext($firm, fn () => $intake->documents()
            ->orderBy('id')
            ->get()
            ->map(fn (Document $document) => [
                'id' => $document->id,
                'original_filename' => $document->original_filename,
                'accepted' => $document->isUsable(),
                'pending' => $document->scan_status === DocumentScanStatus::Pending,
            ])
            ->all());
    }

    /**
     * The ONLY safe query for a Firm-facing document listing —
     * excludes anything not yet scanned clean. A later checkpoint's
     * lead-queue/review UI must use this, never a raw
     * $intake->documents() query, so an infected or still-pending file
     * can never reach Firm staff review.
     *
     * @return Collection<int, Document>
     */
    public function usableDocumentsForFirmReview(Firm $firm, MarketplaceIntake $intake): Collection
    {
        $this->assertBelongsToFirm($firm, $intake);

        return $this->tenantContext->runWithFirmContext(
            $firm,
            fn () => $intake->documents()->get()->filter(fn (Document $document) => $document->isUsable())->values(),
        );
    }

    private function assertBelongsToFirm(Firm $firm, MarketplaceIntake $intake): void
    {
        if ((int) $intake->firm_id !== (int) $firm->id) {
            throw new \RuntimeException('This marketplace intake does not belong to this firm.');
        }
    }
}
