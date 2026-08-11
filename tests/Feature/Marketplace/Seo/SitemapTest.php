<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace\Seo;

use App\Marketplace\Enums\DirectoryPublicationState;
use App\Marketplace\Models\DirectoryAttorney;
use App\Marketplace\Models\DirectoryFirm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SitemapTest — Mission 2 (MyAttorney Marketplace Core), checkpoint
 * 12. sitemap.xml is a sitemap INDEX referencing sitemap-pages.xml +
 * chunked firm/attorney sub-sitemaps (see MarketplaceSitemapService's
 * own docblock for why never a single flat urlset). Only Published
 * listings are ever enumerated — the exact same rule
 * FirmProfileController/AttorneyProfileController 404 against, so a
 * sitemap entry and a live page can never disagree. Served regardless
 * of the myattorney_indexing_enabled flag — the sitemap existing is
 * harmless; X-Robots-Tag/robots.txt are the real gate (see
 * RobotsTxtTest and SecurityHeadersAndSeoTest).
 */
class SitemapTest extends TestCase
{
    use RefreshDatabase;

    public function test_sitemap_index_is_valid_xml_and_references_the_pages_sitemap(): void
    {
        $response = $this->get($this->myAttorneyUrl('/sitemap.xml'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $response->assertSee('<sitemapindex', false);
        $response->assertSee($this->myAttorneyUrl('/sitemap-pages.xml'), false);
    }

    public function test_sitemap_index_references_a_firms_chunk_only_when_a_published_firm_exists(): void
    {
        $withoutFirms = $this->get($this->myAttorneyUrl('/sitemap.xml'));
        $withoutFirms->assertDontSee($this->myAttorneyUrl('/sitemap-firms-1.xml'), false);

        DirectoryFirm::factory()->create(['publication_state' => DirectoryPublicationState::Published]);

        $withFirms = $this->get($this->myAttorneyUrl('/sitemap.xml'));
        $withFirms->assertSee($this->myAttorneyUrl('/sitemap-firms-1.xml'), false);
    }

    public function test_pages_sitemap_lists_the_home_url(): void
    {
        $response = $this->get($this->myAttorneyUrl('/sitemap-pages.xml'));

        $response->assertOk();
        $response->assertSee('<urlset', false);
        $response->assertSee('<loc>'.$this->myAttorneyUrl().'/</loc>', false);
    }

    public function test_firms_sitemap_lists_only_published_firm_urls(): void
    {
        $published = DirectoryFirm::factory()->create([
            'slug' => 'published-firm',
            'publication_state' => DirectoryPublicationState::Published,
        ]);
        DirectoryFirm::factory()->draft()->create(['slug' => 'draft-firm']);
        DirectoryFirm::factory()->suspended()->create(['slug' => 'suspended-firm']);

        $response = $this->get($this->myAttorneyUrl('/sitemap-firms-1.xml'));

        $response->assertOk();
        $response->assertSee('<loc>'.$this->myAttorneyUrl('/firms/published-firm').'</loc>', false);
        $response->assertDontSee('draft-firm', false);
        $response->assertDontSee('suspended-firm', false);
    }

    public function test_attorneys_sitemap_lists_only_published_attorney_urls(): void
    {
        DirectoryAttorney::factory()->create(['slug' => 'published-attorney']);
        DirectoryAttorney::factory()->draft()->create(['slug' => 'draft-attorney']);

        $response = $this->get($this->myAttorneyUrl('/sitemap-attorneys-1.xml'));

        $response->assertOk();
        $response->assertSee('<loc>'.$this->myAttorneyUrl('/attorneys/published-attorney').'</loc>', false);
        $response->assertDontSee('draft-attorney', false);
    }

    public function test_a_sitemap_chunk_page_beyond_the_real_chunk_count_404s(): void
    {
        $response = $this->get($this->myAttorneyUrl('/sitemap-firms-2.xml'));

        $response->assertNotFound();
    }

    public function test_sitemap_urls_are_served_even_when_myattorney_indexing_is_disabled(): void
    {
        config(['hosts.myattorney_indexing_enabled' => false]);

        $this->get($this->myAttorneyUrl('/sitemap.xml'))->assertOk();
    }
}
