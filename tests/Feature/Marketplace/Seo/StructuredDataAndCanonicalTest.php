<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace\Seo;

use App\Marketplace\Models\DirectoryAttorney;
use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Models\FirmOffice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * StructuredDataAndCanonicalTest — Mission 2 (MyAttorney Marketplace
 * Core), checkpoint 12. Canonical link tags, Open Graph tags, and
 * schema.org JSON-LD on the two public profile pages. Asserts the
 * JSON-LD is real, parseable JSON with the expected shape — not just
 * "a <script> tag exists somewhere" — and that it never fabricates
 * fields the underlying data doesn't actually have (no aggregateRating,
 * no image/logo — see MarketplaceStructuredDataService's own docblock).
 */
class StructuredDataAndCanonicalTest extends TestCase
{
    use RefreshDatabase;

    private function extractJsonLd(string $html): array
    {
        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $matches);
        $this->assertNotEmpty($matches, 'No JSON-LD <script> block found in the response.');

        $decoded = json_decode($matches[1], true);
        $this->assertIsArray($decoded, 'The JSON-LD block did not contain valid JSON.');

        return $decoded;
    }

    public function test_firm_profile_has_a_canonical_link_and_open_graph_tags(): void
    {
        DirectoryFirm::factory()->create(['slug' => 'canonical-firm', 'display_name' => 'Canonical Firm PLLC']);

        $response = $this->get($this->myAttorneyUrl('/firms/canonical-firm'));

        $response->assertOk();
        $canonicalUrl = $this->myAttorneyUrl('/firms/canonical-firm');
        $response->assertSee('<link rel="canonical" href="'.$canonicalUrl.'">', false);
        $response->assertSee('<meta property="og:title" content="Canonical Firm PLLC | MyAttorney by FirmsVault">', false);
        $response->assertSee('<meta property="og:url" content="'.$canonicalUrl.'">', false);
    }

    public function test_firm_profile_json_ld_is_a_legal_service_with_no_fabricated_rating(): void
    {
        $firm = DirectoryFirm::factory()->create([
            'slug' => 'jsonld-firm',
            'display_name' => 'JSON-LD Firm PLLC',
            'phone' => '5555550100',
        ]);
        FirmOffice::factory()->forFirm($firm)->withCoordinates(42.3314, -83.0458)->create([
            'city' => 'Detroit',
            'address_line1' => '123 Main St',
        ]);

        $response = $this->get($this->myAttorneyUrl('/firms/jsonld-firm'));
        $data = $this->extractJsonLd($response->getContent());

        $this->assertSame('https://schema.org', $data['@context']);
        $this->assertSame('LegalService', $data['@type']);
        $this->assertSame('JSON-LD Firm PLLC', $data['name']);
        $this->assertSame($this->myAttorneyUrl('/firms/jsonld-firm'), $data['url']);
        $this->assertSame('5555550100', $data['telephone']);
        $this->assertSame('PostalAddress', $data['address']['@type']);
        $this->assertSame('Detroit', $data['address']['addressLocality']);
        $this->assertSame(42.3314, $data['geo']['latitude']);
        $this->assertArrayNotHasKey('aggregateRating', $data);
        $this->assertArrayNotHasKey('image', $data);
    }

    public function test_attorney_profile_has_a_canonical_link_and_json_ld_attorney_type(): void
    {
        DirectoryAttorney::factory()->create(['slug' => 'jsonld-attorney', 'name' => 'Jordan Rivera']);

        $response = $this->get($this->myAttorneyUrl('/attorneys/jsonld-attorney'));

        $response->assertOk();
        $canonicalUrl = $this->myAttorneyUrl('/attorneys/jsonld-attorney');
        $response->assertSee('<link rel="canonical" href="'.$canonicalUrl.'">', false);

        $data = $this->extractJsonLd($response->getContent());
        $this->assertSame('Attorney', $data['@type']);
        $this->assertSame('Jordan Rivera', $data['name']);
        $this->assertSame($canonicalUrl, $data['url']);
        $this->assertArrayNotHasKey('aggregateRating', $data);
    }

    public function test_home_page_has_no_canonical_tag_or_json_ld(): void
    {
        $response = $this->get($this->myAttorneyUrl('/'));

        $response->assertOk();
        $response->assertDontSee('<link rel="canonical"', false);
        $response->assertDontSee('application/ld+json', false);
    }
}
