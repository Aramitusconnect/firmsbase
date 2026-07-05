<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Confirms no real Gmail/Microsoft Graph SDK, network call, or OAuth
 * callback wiring exists anywhere in the Phase 9 email module (project
 * rule — FakeEmailProviderClient only, no network I/O).
 */
class Phase9NoRealProviderCallTest extends TestCase
{
    use RefreshDatabase;

    private const FORBIDDEN_NEEDLES = [
        'GuzzleHttp',
        'Http::',
        'google/apiclient',
        'googleapis.com',
        'accounts.google.com',
        'oauth2.googleapis.com',
        'graph.microsoft.com',
        'login.microsoftonline.com',
        'microsoft-graph',
        'MSAL',
        'curl_exec',
        'fsockopen',
        'file_get_contents(\'http',
    ];

    public function test_email_module_source_never_references_a_real_provider_sdk_or_network_call(): void
    {
        $files = array_merge(
            glob(app_path('Services/*.php')),
            glob(app_path('Services/EmailProvider/*.php')),
        );

        $emailFiles = array_filter($files, fn ($f) => str_contains($f, 'Email') || str_contains($f, 'EmailProvider'));

        $this->assertNotEmpty($emailFiles);

        foreach ($emailFiles as $file) {
            $source = file_get_contents($file);

            foreach (self::FORBIDDEN_NEEDLES as $needle) {
                $this->assertStringNotContainsString($needle, $source, "{$file} must not reference: {$needle}");
            }
        }
    }

    public function test_no_oauth_callback_route_or_controller_exists(): void
    {
        $this->assertFalse(is_dir(app_path('Http/Controllers/Email')));

        $controllerFiles = glob(app_path('Http/Controllers/*.php')) ?: [];
        foreach ($controllerFiles as $file) {
            $this->assertStringNotContainsString('oauth', strtolower($file));
        }
    }
}
