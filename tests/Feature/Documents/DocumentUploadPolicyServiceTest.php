<?php

namespace Tests\Feature\Documents;

use App\Services\DocumentUploadPolicyService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DocumentUploadPolicyServiceTest extends TestCase
{
    private DocumentUploadPolicyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new DocumentUploadPolicyService();
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function blockedExtensionProvider(): array
    {
        return [
            'exe' => ['malware.exe'],
            'bat' => ['script.bat'],
            'sh' => ['script.sh'],
            'js' => ['payload.js'],
            'ps1' => ['payload.ps1'],
            'apk' => ['app.apk'],
        ];
    }

    #[DataProvider('blockedExtensionProvider')]
    public function test_dangerous_file_extensions_are_rejected(string $filename): void
    {
        $this->assertFalse($this->service->isExtensionAllowed($filename));

        $this->expectException(\InvalidArgumentException::class);
        $this->service->assertUploadIsAllowed($filename, 1024);
    }

    public function test_an_executable_disguised_with_an_allowed_looking_name_is_still_blocked_by_true_extension(): void
    {
        // "not-a-virus.exe" — even though it could be renamed, the
        // actual file extension is what is evaluated, and .exe is
        // always rejected regardless of any surrounding filename text.
        $this->assertFalse($this->service->isExtensionAllowed('passport-scan.exe'));
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function allowedExtensionProvider(): array
    {
        return [
            'pdf' => ['passport.pdf'],
            'jpg' => ['photo.jpg'],
            'png' => ['photo.png'],
            'docx' => ['letter.docx'],
        ];
    }

    #[DataProvider('allowedExtensionProvider')]
    public function test_standard_document_extensions_are_allowed(string $filename): void
    {
        $this->assertTrue($this->service->isExtensionAllowed($filename));
        $this->service->assertUploadIsAllowed($filename, 1024);
        $this->addToAssertionCount(1); // no exception thrown
    }

    public function test_an_unrecognized_extension_not_on_either_list_is_rejected(): void
    {
        $this->assertFalse($this->service->isExtensionAllowed('data.xyz'));
    }

    public function test_a_file_over_the_maximum_size_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->service->assertUploadIsAllowed('passport.pdf', 26_214_401);
    }

    public function test_a_zero_byte_file_is_rejected(): void
    {
        $this->assertFalse($this->service->isSizeAllowed(0));
    }

    public function test_a_file_at_exactly_the_maximum_size_is_allowed(): void
    {
        $this->assertTrue($this->service->isSizeAllowed(26_214_400));
    }
}
