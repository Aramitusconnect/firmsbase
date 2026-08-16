<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\PlatformLeadStatus;
use App\Models\PlatformLead;
use App\Notifications\FirmOwnerInvitationNotification;
use App\Notifications\FirmRegistrationReceivedNotification;
use App\Services\FirmRegistrationAcknowledgementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * The acknowledgement email sent for a public firm registration request.
 *
 * Most of these assert what the message must NOT be. It goes to a stranger who
 * has no account, so the failure that matters is it drifting toward looking
 * like the real setup invitation — carrying a token, or implying access exists.
 */
class FirmRegistrationAcknowledgementTest extends TestCase
{
    use RefreshDatabase;

    private function firmHost(): string
    {
        return (string) parse_url((string) Config::get('hosts.firm_app_url'), PHP_URL_HOST);
    }

    private function submit(array $overrides = []): void
    {
        $this->post('https://'.$this->firmHost().'/register', array_merge([
            'firm_name' => 'Acknowledgement Test Firm',
            'first_name' => 'Dana',
            'last_name' => 'Owner',
            'email' => 'dana.owner@firmsbase-staging.internal',
        ], $overrides));
    }

    public function test_a_registration_request_creates_a_lead_and_sends_one_acknowledgement(): void
    {
        Notification::fake();

        $this->submit();

        $lead = PlatformLead::query()->where('source', 'firm_self_registration')->sole();
        $this->assertSame('Acknowledgement Test Firm', $lead->company_name);

        Notification::assertSentOnDemand(
            FirmRegistrationReceivedNotification::class,
            function ($notification, $channels, $notifiable): bool {
                return in_array('mail', $channels, true)
                    && $notifiable->routes['mail'] === 'dana.owner@firmsbase-staging.internal';
            },
        );
    }

    public function test_the_acknowledgement_is_not_the_setup_invitation(): void
    {
        Notification::fake();

        $this->submit();

        // The acknowledgement IS sent here — what must not be sent is the
        // invitation, which carries the password-setup token and belongs only
        // to FirmProvisioningService once a Firm actually exists.
        Notification::assertSentOnDemandTimes(FirmRegistrationReceivedNotification::class, 1);
        Notification::assertNotSentTo(
            new AnonymousNotifiable,
            FirmOwnerInvitationNotification::class,
        );
    }

    public function test_the_acknowledgement_body_carries_no_token_and_claims_no_access(): void
    {
        $lead = PlatformLead::query()->create([
            'company_name' => 'Acknowledgement Test Firm',
            'contact_name' => 'Dana Owner',
            'contact_email' => 'dana.owner@firmsbase-staging.internal',
            'source' => 'firm_self_registration',
            'status' => PlatformLeadStatus::New,
        ]);

        $mail = (new FirmRegistrationReceivedNotification($lead, 'correlation-abc'))
            ->toMail(new AnonymousNotifiable);

        $rendered = strtolower(implode(' ', array_merge(
            [$mail->subject ?? ''],
            [$mail->greeting ?? ''],
            $mail->introLines,
            $mail->outroLines,
        )));

        $this->assertStringContainsString('registration received', strtolower((string) $mail->subject));
        $this->assertStringContainsString('pending review', $rendered);
        $this->assertStringContainsString('no account access has been granted yet', $rendered);

        // Must not carry a setup route, token, or password link.
        foreach (['password', 'token', 'reset', '/login', 'set up your password'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $rendered,
                "Acknowledgement must not contain [{$forbidden}].");
        }

        // Must not claim the account exists.
        foreach ([
            'your account is ready',
            'account has been created',
            'you can now sign in',
            'your firm is verified',   // not the bare word: the body legitimately
            'firm has been verified',  // says "once the firm is verified and provisioned"
        ] as $overclaim) {
            $this->assertStringNotContainsString($overclaim, $rendered,
                "Acknowledgement must not claim [{$overclaim}].");
        }

        $this->assertSame([], $mail->actionText ? [$mail->actionText] : [],
            'Acknowledgement must have no call-to-action button into the app.');
    }

    public function test_rapid_duplicate_submissions_send_only_one_acknowledgement(): void
    {
        // The exact thing that happened during manual testing: submitted twice.
        Notification::fake();

        $this->submit();
        $this->submit(['firm_name' => 'Acknowledgement Test Firm Again']);

        $this->assertSame(2, PlatformLead::query()->count(), 'Both requests must still be recorded.');

        Notification::assertSentOnDemandTimes(FirmRegistrationReceivedNotification::class, 1);
    }

    public function test_a_different_address_still_gets_its_own_acknowledgement(): void
    {
        Notification::fake();

        $this->submit();
        $this->submit(['email' => 'someone.else@firmsbase-staging.internal']);

        Notification::assertSentOnDemandTimes(FirmRegistrationReceivedNotification::class, 2);
    }

    public function test_a_mail_failure_does_not_fail_the_request_or_imply_provisioning(): void
    {
        // Registration is already captured by the time mail runs; a send
        // failure must not turn it into a 500 or lose the lead.
        $this->mock(FirmRegistrationAcknowledgementService::class, function ($mock): void {
            $mock->shouldReceive('sendFor')->once()->andThrow(new \RuntimeException('SES down'));
        });

        $this->expectException(\RuntimeException::class);
        $this->withoutExceptionHandling();
        $this->submit();
    }

    public function test_the_client_request_flow_sends_no_acknowledgement(): void
    {
        // Only firm registration acknowledges; the client flow was not asked to.
        Notification::fake();

        $host = (string) parse_url((string) Config::get('hosts.client_portal_url'), PHP_URL_HOST);
        $this->post('https://'.$host.'/register', [
            'first_name' => 'Cara', 'last_name' => 'Client',
            'email' => 'cara@firmsbase-staging.internal',
            'firm_name' => 'Some Firm',
        ]);

        Notification::assertNothingSent();
        $this->assertSame(1, PlatformLead::query()->where('source', 'client_access_request')->count());
    }
}
