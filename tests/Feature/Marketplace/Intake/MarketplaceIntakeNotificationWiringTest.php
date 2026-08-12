<?php

declare(strict_types=1);

namespace Tests\Feature\Marketplace\Intake;

use App\Marketplace\Notifications\MarketplaceIntakeAcceptedNotification;
use App\Marketplace\Notifications\MarketplaceIntakeDeclinedNotification;
use App\Marketplace\Services\MarketplaceIntakeService;
use App\Models\Firm;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Mission 3 (MyAttorney Conversion + AI Intake), checkpoint 13 — proves
 * markAccepted()/markDeclined() actually notify the prospect at their
 * own prospect_email, via the real DB::afterCommit()-deferred send
 * path. DatabaseMigrations (not RefreshDatabase) for the same
 * DB::afterCommit()-under-test reason documented throughout
 * tests/Feature/Webhooks/Wiring/* — RefreshDatabase wraps the whole
 * test in an outer transaction that never commits, so afterCommit()
 * callbacks queued during the test would never fire.
 */
class MarketplaceIntakeNotificationWiringTest extends TestCase
{
    use DatabaseMigrations;

    private MarketplaceIntakeService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->service = new MarketplaceIntakeService;
    }

    private function submittedIntake(Firm $firm, string $prospectEmail = 'prospect@example.com')
    {
        $intake = $this->service->start($firm);
        $this->runWithFirmContext($firm, fn () => $intake->update(['prospect_email' => $prospectEmail, 'prospect_name' => 'Jordan Prospect']));

        return $this->service->markSubmitted($firm, $intake);
    }

    public function test_mark_accepted_notifies_the_prospect_at_their_own_email(): void
    {
        $firm = Firm::factory()->create();
        $submitted = $this->submittedIntake($firm, 'prospect@example.com');

        $this->service->markAccepted($firm, $submitted);

        Notification::assertSentOnDemand(
            MarketplaceIntakeAcceptedNotification::class,
            fn ($notification, $channels, $notifiable) => $notifiable instanceof AnonymousNotifiable
                && $notifiable->routes['mail'] === 'prospect@example.com',
        );
    }

    public function test_mark_declined_notifies_the_prospect_at_their_own_email(): void
    {
        $firm = Firm::factory()->create();
        $submitted = $this->submittedIntake($firm, 'prospect@example.com');

        $this->service->markDeclined($firm, $submitted, 'Outside our practice areas.');

        Notification::assertSentOnDemand(
            MarketplaceIntakeDeclinedNotification::class,
            fn ($notification, $channels, $notifiable) => $notifiable instanceof AnonymousNotifiable
                && $notifiable->routes['mail'] === 'prospect@example.com',
        );
    }

    public function test_mark_declined_notification_never_carries_the_internal_decline_reason(): void
    {
        $firm = Firm::factory()->create();
        $submitted = $this->submittedIntake($firm);

        $this->service->markDeclined($firm, $submitted, 'Confidential internal note: possible conflict of interest.');

        Notification::assertSentOnDemand(
            MarketplaceIntakeDeclinedNotification::class,
            function (MarketplaceIntakeDeclinedNotification $notification) {
                $mail = $notification->toMail(new AnonymousNotifiable);
                $rendered = implode(' ', $mail->introLines).' '.implode(' ', $mail->outroLines).' '.$mail->subject;

                return ! str_contains($rendered, 'Confidential internal note')
                    && ! str_contains($rendered, 'conflict of interest');
            },
        );
    }

    public function test_mark_accepted_still_succeeds_even_when_no_prospect_email_is_on_file(): void
    {
        $firm = Firm::factory()->create();
        $intake = $this->service->start($firm);
        $submitted = $this->service->markSubmitted($firm, $intake);

        $accepted = $this->service->markAccepted($firm, $submitted);

        $this->assertNotNull($accepted->accepted_at);
        Notification::assertNothingSent();
    }
}
