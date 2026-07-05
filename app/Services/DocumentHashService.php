<?php

namespace App\Services;

use App\Enums\HashAlgorithm;
use App\Enums\SignatureSourceDocumentType;
use App\Models\Document;
use App\Models\DocumentHash;
use App\Models\FirmUser;
use App\Models\GeneratedDocument;
use App\ValueObjects\DocumentHashRecordResult;

/**
 * DocumentHashService — hash VALUES are caller-supplied, exactly
 * mirroring the existing precedent of documents.file_hash (confirmed
 * by inspecting DocumentSecurityService::upload(), which accepts
 * $fileHash as a parameter and never computes one internally). No real
 * file storage/rendering pipeline exists anywhere in this codebase yet
 * to hash real bytes from, so this service does not fabricate a hash —
 * it durably and immutably records whatever value the caller (the
 * eventual real storage layer) supplies. What IS real here: the
 * immutability guarantee, the algorithm typing, and the association to
 * the correct source document.
 */
class DocumentHashService
{
    public function recordForDocument(
        Document $document,
        string $hashValue,
        ?FirmUser $recordedBy = null,
        HashAlgorithm $algorithm = HashAlgorithm::Sha256,
    ): DocumentHashRecordResult {
        $hash = DocumentHash::create([
            'firm_id' => $document->firm_id,
            'source_document_type' => SignatureSourceDocumentType::Document,
            'document_id' => $document->id,
            'algorithm' => $algorithm,
            'hash_value' => $hashValue,
            'recorded_by_firm_user_id' => $recordedBy?->id,
            'recorded_at' => now(),
        ]);

        return new DocumentHashRecordResult($hash->id, $algorithm, $hashValue, $hash->recorded_at);
    }

    public function recordForGeneratedDocument(
        GeneratedDocument $generatedDocument,
        string $hashValue,
        ?FirmUser $recordedBy = null,
        HashAlgorithm $algorithm = HashAlgorithm::Sha256,
    ): DocumentHashRecordResult {
        $hash = DocumentHash::create([
            'firm_id' => $generatedDocument->firm_id,
            'source_document_type' => SignatureSourceDocumentType::GeneratedDocument,
            'generated_document_id' => $generatedDocument->id,
            'algorithm' => $algorithm,
            'hash_value' => $hashValue,
            'recorded_by_firm_user_id' => $recordedBy?->id,
            'recorded_at' => now(),
        ]);

        return new DocumentHashRecordResult($hash->id, $algorithm, $hashValue, $hash->recorded_at);
    }

    public function latestForDocument(Document $document): ?DocumentHash
    {
        return DocumentHash::query()
            ->where('document_id', $document->id)
            ->latest('recorded_at')
            ->first();
    }

    public function latestForGeneratedDocument(GeneratedDocument $generatedDocument): ?DocumentHash
    {
        return DocumentHash::query()
            ->where('generated_document_id', $generatedDocument->id)
            ->latest('recorded_at')
            ->first();
    }
}
