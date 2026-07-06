<?php

namespace Tests\Feature\Deployment\VersionSkew;

use App\Services\VersionSkewPolicyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VersionSkewPolicyServiceTest extends TestCase
{
    use RefreshDatabase;

    private VersionSkewPolicyService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new VersionSkewPolicyService();
    }

    public function test_the_same_version_passes(): void
    {
        $result = $this->service->check('2026.7.0', '2026.7.0');

        $this->assertTrue($result->withinPolicy);
        $this->assertSame(0, $result->minorVersionsBehind);
    }

    public function test_one_minor_version_behind_passes(): void
    {
        $result = $this->service->check('2026.6.0', '2026.7.0');

        $this->assertTrue($result->withinPolicy);
        $this->assertSame(1, $result->minorVersionsBehind);
    }

    public function test_two_minor_versions_behind_fails(): void
    {
        $result = $this->service->check('2026.5.0', '2026.7.0');

        $this->assertFalse($result->withinPolicy);
        $this->assertSame(2, $result->minorVersionsBehind);
    }

    public function test_a_different_major_version_fails(): void
    {
        $result = $this->service->check('2025.7.0', '2026.7.0');

        $this->assertFalse($result->withinPolicy);
    }

    public function test_an_instance_ahead_of_saas_fails(): void
    {
        $result = $this->service->check('2026.8.0', '2026.7.0');

        $this->assertFalse($result->withinPolicy);
    }
}
