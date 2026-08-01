<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use Aws\Ses\SesClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Transport\SesTransport;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * SesMailerTransportTest — FIRMSVAULT STAGING SES MAILER FIX.
 *
 * Proves the actual gap this branch fixes: MAIL_MAILER=ses previously
 * failed with `Error: Class "Aws\Ses\SesClient" not found` because
 * aws/aws-sdk-php was never a Composer dependency (this app never
 * previously sent mail via any AWS service — confirmed via a live
 * staging diagnostic during this fix's own investigation). No amount
 * of environment-variable configuration could have fixed that; only
 * adding the real PHP dependency does.
 *
 * Also proves the ECS task-role credential-provider requirement:
 * config/services.php's `ses` block passes `env('AWS_ACCESS_KEY_ID')`/
 * `env('AWS_SECRET_ACCESS_KEY')` straight through, which resolve to
 * null whenever those env vars are absent (exactly the staging ECS
 * task definition's own shape — no such env vars are set there). The
 * AWS SDK's own documented behavior is to fall back to its default
 * credential provider chain (which discovers ECS task-role credentials
 * via the container credentials endpoint) whenever an explicit
 * key/secret is not supplied — passing `null` explicitly triggers that
 * fallback exactly like omitting the option entirely. This test proves
 * the transport constructs successfully under that exact null-credential
 * condition; it does not and cannot prove real ECS task-role network
 * resolution from a local test process, which has no container
 * credentials endpoint to reach.
 */
final class SesMailerTransportTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_ses_client_class_is_available(): void
    {
        $this->assertTrue(
            class_exists(SesClient::class),
            'aws/aws-sdk-php must be present so Laravel\'s SES mail transport can actually be constructed.'
        );
    }

    /**
     * The real staging ECS task definition (confirmed via `aws ecs
     * describe-task-definition`) carries no AWS_ACCESS_KEY_ID/
     * AWS_SECRET_ACCESS_KEY key at all — forced to null here rather than
     * relying on the local `.env.example` convention (which sets these
     * to an empty string, a different value to the AWS SDK than a truly
     * absent key) so this test is deterministic regardless of the local
     * dev environment's own `.env` contents. Proves the SES transport
     * still constructs successfully under that exact condition — the
     * AWS SDK's documented behavior is to fall back to its default
     * credential provider chain (which resolves ECS task-role
     * credentials via the container credentials endpoint in a real
     * task) whenever no explicit key/secret is supplied.
     */
    public function test_the_ses_mailer_resolves_without_static_credentials(): void
    {
        config([
            'mail.default' => 'ses',
            'services.ses.key' => null,
            'services.ses.secret' => null,
            'services.ses.region' => 'us-east-1',
        ]);

        $mailer = Mail::mailer('ses');

        $this->assertInstanceOf(SesTransport::class, $mailer->getSymfonyTransport());
    }
}
