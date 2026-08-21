<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace\Intake;

use App\Marketplace\Models\DirectoryFirm;
use App\Marketplace\Models\MarketplaceIntake;
use App\Models\Firm;
use App\Models\IntakeTemplate;
use App\Models\PracticeArea;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Each MyAttorney concern gets its own rate-limit budget.
 *
 * Found by the owner during manual staging testing: one fresh click of "Start
 * Secure Intake" returned 429. The cause was not the limit being too low — it
 * was that an inline throttle:5,1 shares its counter with every other route on
 * the host. ThrottleRequests keys on domain + client IP and excludes the URI,
 * so reading a firm's profile a few times spent the intake budget, and the
 * whole host effectively inherited the strictest route's limit.
 */
final class MyAttorneyRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        RateLimiter::clear('myattorney-public');
        RateLimiter::clear('myattorney-intake-start');
    }

    private function listing(string $slug): DirectoryFirm
    {
        IntakeTemplate::factory()->marketplaceDefault()->create(['is_active' => true]);
        $directoryFirm = DirectoryFirm::factory()->member()->create([
            'firm_id' => Firm::factory()->create()->id,
            'slug' => $slug,
            'accepting_inquiries' => true,
        ]);
        $area = PracticeArea::factory()->create(['is_active' => true, 'is_marketplace_visible' => true]);
        $directoryFirm->practiceAreas()->sync([$area->id => ['source_type' => 'firm_submitted']]);

        return $directoryFirm;
    }

    public function test_reading_a_profile_does_not_spend_the_start_intake_budget(): void
    {
        // The exact reproduction: browse, then click once.
        $directoryFirm = $this->listing('budget-check');

        for ($i = 0; $i < 8; $i++) {
            $this->get($this->myAttorneyUrl("/firms/{$directoryFirm->slug}"))->assertOk();
        }

        $response = $this->post($this->myAttorneyUrl("/firms/{$directoryFirm->slug}/start-intake"));

        $response->assertRedirect();
        $this->assertStringContainsString('/intake/', $response->headers->get('Location'));
    }

    public function test_the_public_read_limit_is_not_consumed_by_starting_intakes(): void
    {
        // The mirror image: the budgets are separate in both directions.
        $directoryFirm = $this->listing('reverse-check');

        for ($i = 0; $i < 6; $i++) {
            $this->post($this->myAttorneyUrl("/firms/{$directoryFirm->slug}/start-intake"));
        }

        $this->get($this->myAttorneyUrl("/firms/{$directoryFirm->slug}"))->assertOk();
    }

    public function test_the_start_intake_limit_still_bites(): void
    {
        // Isolating the buckets must not mean removing the ceiling: this route
        // creates state and stays the tightest limit on the host.
        $directoryFirm = $this->listing('ceiling-check');

        $statuses = [];

        for ($i = 0; $i < 12; $i++) {
            $statuses[] = $this->post($this->myAttorneyUrl("/firms/{$directoryFirm->slug}/start-intake"))->getStatusCode();
        }

        $this->assertContains(429, $statuses, 'Start Intake must still be rate limited.');
        $this->assertSame(10, count(array_filter($statuses, fn ($s) => $s !== 429)), 'Exactly ten attempts should be allowed per minute.');
    }

    public function test_a_throttled_visitor_sees_a_professional_page_not_a_bare_error(): void
    {
        $directoryFirm = $this->listing('message-check');

        $response = null;

        for ($i = 0; $i < 12; $i++) {
            $attempt = $this->post($this->myAttorneyUrl("/firms/{$directoryFirm->slug}/start-intake"));

            if ($attempt->getStatusCode() === 429) {
                $response = $attempt;
                break;
            }
        }

        $this->assertNotNull($response, 'Expected to reach the rate limit.');
        $response->assertSee('One moment please');
        $response->assertSee('Please try again in about', false);
        $response->assertDontSee('Too Many Requests');
    }

    public function test_being_throttled_creates_no_intake(): void
    {
        $directoryFirm = $this->listing('no-side-effect');

        for ($i = 0; $i < 12; $i++) {
            $this->post($this->myAttorneyUrl("/firms/{$directoryFirm->slug}/start-intake"));
        }

        $created = $this->runWithFirmContext(
            $directoryFirm->firm,
            fn () => MarketplaceIntake::query()->where('directory_firm_id', $directoryFirm->id)->count(),
        );

        $this->assertSame(10, $created, 'A refused request must not create an intake.');
    }
}
