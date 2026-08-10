<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        // Mission 1 (Domain & Security Boundary Architecture) moved
        // the marketing welcome page to a domain-scoped route
        // (firmsvault.com specifically, not every host) — a bare '/'
        // on the default test host no longer matches.
        $response = $this->get($this->marketingUrl('/'));

        $response->assertStatus(200);
    }
}
