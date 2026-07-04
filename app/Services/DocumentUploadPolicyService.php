<?php

namespace App\Services;

/**
 * DocumentUploadPolicyService — the ONLY place upload file-type/
 * extension rules are evaluated, before a Document row is ever
 * created. Rejects dangerous extensions/executables outright,
 * regardless of an allowlist match on mime type (defense-in-depth:
 * an executable renamed with a document extension is still rejected
 * by extension).
 */
class DocumentUploadPolicyService
{
    /**
     * @var array<int, string>
     */
    private array $allowedExtensions = [
        'pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'tif', 'tiff', 'txt',
    ];

    /**
     * @var array<int, string>
     */
    private array $blockedExtensions = [
        'exe', 'bat', 'cmd', 'sh', 'com', 'msi', 'scr', 'js', 'vbs',
        'ps1', 'jar', 'app', 'dmg', 'apk',
    ];

    private int $maxSizeBytes = 26_214_400; // 25 MB

    public function isExtensionAllowed(string $originalFilename): bool
    {
        $extension = $this->extensionOf($originalFilename);

        if (in_array($extension, $this->blockedExtensions, true)) {
            return false;
        }

        return in_array($extension, $this->allowedExtensions, true);
    }

    public function isSizeAllowed(int $sizeBytes): bool
    {
        return $sizeBytes > 0 && $sizeBytes <= $this->maxSizeBytes;
    }

    /**
     * Throws with a specific, user-facing-safe reason rather than
     * returning a bare bool, so callers (DocumentSecurityService) can
     * surface why an upload was rejected.
     */
    public function assertUploadIsAllowed(string $originalFilename, int $sizeBytes): void
    {
        $extension = $this->extensionOf($originalFilename);

        if (in_array($extension, $this->blockedExtensions, true)) {
            throw new \InvalidArgumentException("File extension '.{$extension}' is not allowed (executable/dangerous file type).");
        }

        if (! in_array($extension, $this->allowedExtensions, true)) {
            throw new \InvalidArgumentException("File extension '.{$extension}' is not an allowed document type.");
        }

        if (! $this->isSizeAllowed($sizeBytes)) {
            throw new \InvalidArgumentException('File size exceeds the maximum allowed upload size.');
        }
    }

    private function extensionOf(string $filename): string
    {
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    }
}
