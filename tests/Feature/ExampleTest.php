<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example. Mission 1 (canonical reconstruction) rejects
     * any Host other than the six canonical FirmsVault hostnames, so the
     * root path is only reachable on the marketing host now.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        $response = $this->get($this->marketingUrl('/'));

        $response->assertStatus(200);
    }
}
