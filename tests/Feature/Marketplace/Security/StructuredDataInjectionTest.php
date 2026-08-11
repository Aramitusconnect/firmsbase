<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace\Security;

use App\Marketplace\Models\DirectoryAttorney;
use App\Marketplace\Models\DirectoryFirm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * StructuredDataInjectionTest — Mission 2 (MyAttorney Marketplace
 * Core), checkpoint 14 (security hardening). Covers hardening test
 * matrix item BF: a real regression test for the JSON-LD
 * script-injection vector this checkpoint closed —
 * `json_encode($structuredData, JSON_UNESCAPED_SLASHES)` left a
 * literal `</script>` inside a firm/attorney's own (CSV-imported,
 * untrusted) description/biography free to break out of the
 * `<script type="application/ld+json">` block. Proves the fix
 * (JSON_HEX_TAG et al.) by planting the exact payload and asserting
 * the literal, unescaped closing tag never appears in the response
 * body.
 */
final class StructuredDataInjectionTest extends TestCase
{
    use RefreshDatabase;

    private const PAYLOAD = '</script><script>alert(document.cookie)</script>';

    public function test_a_firm_description_containing_a_script_close_tag_cannot_break_out_of_the_json_ld_block(): void
    {
        DirectoryFirm::factory()->create([
            'slug' => 'injection-firm',
            'description' => 'Legitimate description. '.self::PAYLOAD,
        ]);

        $response = $this->get($this->myAttorneyUrl('/firms/injection-firm'));

        $response->assertOk();
        // The literal, unescaped attack string must never appear
        // verbatim in the response — if it did, the browser's HTML
        // tokenizer would end the <script> element on the embedded
        // </script> regardless of it being "inside a JSON string".
        $response->assertDontSee(self::PAYLOAD, false);
    }

    public function test_an_attorney_biography_containing_a_script_close_tag_cannot_break_out_of_the_json_ld_block(): void
    {
        DirectoryAttorney::factory()->create([
            'slug' => 'injection-attorney',
            'biography' => 'Legitimate biography. '.self::PAYLOAD,
        ]);

        $response = $this->get($this->myAttorneyUrl('/attorneys/injection-attorney'));

        $response->assertOk();
        $response->assertDontSee(self::PAYLOAD, false);
    }

    public function test_the_json_ld_block_remains_valid_parseable_json_with_the_payload_present(): void
    {
        DirectoryFirm::factory()->create([
            'slug' => 'valid-json-firm',
            'description' => self::PAYLOAD,
        ]);

        $response = $this->get($this->myAttorneyUrl('/firms/valid-json-firm'));

        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $response->getContent(), $matches);
        $this->assertNotEmpty($matches, 'No JSON-LD block found.');

        $decoded = json_decode($matches[1], true);
        $this->assertIsArray($decoded);
        $this->assertStringContainsString(self::PAYLOAD, $decoded['description']);
    }
}
