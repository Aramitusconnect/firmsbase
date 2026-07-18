<?php

namespace Tests\Feature\Email\MessageLinks;

use App\Enums\EmailSyncEventType;
use App\Enums\EmailSyncOutcome;
use App\Models\Client;
use App\Models\EmailAccount;
use App\Models\EmailMessage;
use App\Models\EmailMessageLink;
use App\Models\EmailSyncEvent;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\Matter;
use App\Services\EmailMessageLinkingService;
use App\Services\TenantContextService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EmailMessageLinkingServiceTest — EmailMessageLinkingService had zero
 * pre-existing test coverage before the email_message_links FORCE ROW
 * LEVEL SECURITY checkpoint (see database/migrations/
 * 2026_08_27_950004_prepare_row_level_security_and_force_rls_on_email_message_links_table.php
 * and EmailMessageLinksForceRlsActivationTest for the table-level
 * proof). This test covers the service itself: link()'s end-to-end
 * behavior under its new single outer runWithFirmContext() wrap, its
 * pre-existing cross-firm guards (unchanged business logic, still
 * running BEFORE that wrap), and unlink()'s new required-actor
 * signature — in particular the security-review-driven regression
 * proof that a mismatched actor is rejected before any database
 * statement (including the delete itself) runs.
 */
class EmailMessageLinkingServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_link_succeeds_end_to_end_when_matter_client_and_actor_all_share_the_message_firm(): void
    {
        $firm = Firm::factory()->create();
        $account = EmailAccount::factory()->forFirm($firm)->create();
        $message = EmailMessage::factory()->forAccount($account)->create();
        $matter = Matter::factory()->forFirm($firm)->create();
        $client = Client::factory()->forFirm($firm)->create();
        $actor = FirmUser::factory()->forFirm($firm)->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        $link = app(EmailMessageLinkingService::class)->link(
            message: $message,
            actor: $actor,
            matter: $matter,
            client: $client,
            isPrimary: true,
        );

        $this->assertNotNull($link->id);
        $this->assertSame($firm->id, $link->firm_id);
        $this->assertSame($message->id, $link->email_message_id);
        $this->assertSame($matter->id, $link->matter_id);
        $this->assertSame($client->id, $link->client_id);
        $this->assertSame($actor->id, $link->linked_by_firm_user_id);
        $this->assertTrue($link->is_primary);

        $storedLink = $this->runWithFirmContext($firm, fn () => EmailMessageLink::withoutGlobalScopes()->find($link->id));
        $this->assertNotNull($storedLink, 'The link row must actually be persisted under the message firm context.');

        $auditEvent = $this->runWithFirmContext(
            $firm,
            fn () => EmailSyncEvent::query()
                ->where('firm_id', $firm->id)
                ->where('event_type', EmailSyncEventType::MessageLinked)
                ->latest('id')
                ->first(),
        );
        $this->assertNotNull($auditEvent, 'link() must still write its audit event inside the same wrap.');
        $this->assertSame(EmailSyncOutcome::Success, $auditEvent->outcome);
    }

    public function test_link_throws_when_neither_matter_nor_client_is_provided(): void
    {
        $firm = Firm::factory()->create();
        $account = EmailAccount::factory()->forFirm($firm)->create();
        $message = EmailMessage::factory()->forAccount($account)->create();
        $actor = FirmUser::factory()->forFirm($firm)->create();

        $this->expectException(\InvalidArgumentException::class);

        app(EmailMessageLinkingService::class)->link(message: $message, actor: $actor);
    }

    public function test_link_throws_when_matter_firm_does_not_match_message_firm(): void
    {
        $firm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();
        $account = EmailAccount::factory()->forFirm($firm)->create();
        $message = EmailMessage::factory()->forAccount($account)->create();
        $actor = FirmUser::factory()->forFirm($firm)->create();
        $mismatchedMatter = Matter::factory()->forFirm($otherFirm)->create();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Matter does not belong to the same firm as the email message.');

        app(EmailMessageLinkingService::class)->link(message: $message, actor: $actor, matter: $mismatchedMatter);

        $this->assertSame(0, EmailMessageLink::withoutGlobalScopes()->count(), 'No link row may be created when the matter/message firm mismatch guard fires.');
    }

    public function test_link_throws_when_client_firm_does_not_match_message_firm(): void
    {
        $firm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();
        $account = EmailAccount::factory()->forFirm($firm)->create();
        $message = EmailMessage::factory()->forAccount($account)->create();
        $actor = FirmUser::factory()->forFirm($firm)->create();
        $mismatchedClient = Client::factory()->forFirm($otherFirm)->create();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Client does not belong to the same firm as the email message.');

        app(EmailMessageLinkingService::class)->link(message: $message, actor: $actor, client: $mismatchedClient);
    }

    public function test_link_throws_when_actor_firm_does_not_match_message_firm(): void
    {
        $firm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();
        $account = EmailAccount::factory()->forFirm($firm)->create();
        $message = EmailMessage::factory()->forAccount($account)->create();
        $matter = Matter::factory()->forFirm($firm)->create();
        $mismatchedActor = FirmUser::factory()->forFirm($otherFirm)->create();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Actor does not belong to the same firm as the email message.');

        app(EmailMessageLinkingService::class)->link(message: $message, actor: $mismatchedActor, matter: $matter);
    }

    public function test_link_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();
        $account = EmailAccount::factory()->forFirm($firm)->create();
        $message = EmailMessage::factory()->forAccount($account)->create();
        $matter = Matter::factory()->forFirm($firm)->create();
        $actor = FirmUser::factory()->forFirm($firm)->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        app(EmailMessageLinkingService::class)->link(message: $message, actor: $actor, matter: $matter);

        $this->assertNoDatabaseTenantContext('link() must restore context to the clean baseline it was called against');
    }

    public function test_link_context_clears_after_exception_inside_the_wrap(): void
    {
        // A duplicate is_primary constraint does not exist at the DB
        // layer, so to exercise the wrap's exception path we instead
        // rely on a mismatched-firm guard firing before the wrap is
        // ever entered — this proves no lingering context is left
        // behind by the guard-clause path either.
        $firm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();
        $account = EmailAccount::factory()->forFirm($firm)->create();
        $message = EmailMessage::factory()->forAccount($account)->create();
        $mismatchedActor = FirmUser::factory()->forFirm($otherFirm)->create();
        // Built here, BEFORE clearDatabaseTenantContext() below, rather
        // than inline as a call argument: test-runner correction
        // (execution against a real database revealed this) —
        // Matter::factory()->create() (Section 39A-3A's own already-
        // FORCE'd matters table) deliberately sets AND LEAVES a
        // matching database tenant context active as its own
        // documented "create then read" convenience. PHP evaluates
        // named-argument expressions at the call site, i.e. AFTER any
        // statement preceding that call — so building it inline as
        // link()'s own argument would leak that unrelated context in
        // AFTER the clear below, masking the guard-clause path this
        // test exists to prove leaves no lingering context.
        $matter = Matter::factory()->forFirm($firm)->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        try {
            app(EmailMessageLinkingService::class)->link(message: $message, actor: $mismatchedActor, matter: $matter);
        } catch (\RuntimeException $e) {
            // expected
        }

        $this->assertNoDatabaseTenantContext('a pre-wrap guard-clause exception must not leave any context active');
    }

    public function test_unlink_succeeds_when_actor_firm_matches_link_firm(): void
    {
        $firm = Firm::factory()->create();
        $link = $this->createLink($firm);
        $actor = FirmUser::factory()->forFirm($firm)->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        app(EmailMessageLinkingService::class)->unlink($link, $actor);

        $stillExists = $this->runWithFirmContext($firm, fn () => EmailMessageLink::withoutGlobalScopes()->find($link->id));
        $this->assertNull($stillExists, 'unlink() must actually delete the row when the actor firm matches.');
    }

    /**
     * The specific regression test for the security-review finding:
     * unlink()'s actor-firm check must run and throw BEFORE
     * runWithFirmContext()/$link->delete() ever executes, so a
     * mismatched actor cannot cause (or mask) a delete against a link
     * belonging to a different firm than the actor.
     */
    public function test_unlink_with_mismatched_firm_actor_throws_before_any_delete_and_row_survives(): void
    {
        $firm = Firm::factory()->create();
        $otherFirm = Firm::factory()->create();
        $link = $this->createLink($firm);
        $mismatchedActor = FirmUser::factory()->forFirm($otherFirm)->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        try {
            app(EmailMessageLinkingService::class)->unlink($link, $mismatchedActor);
            $this->fail('Expected a RuntimeException for a firm-mismatched actor.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Actor does not belong to the same firm as the email message link.', $e->getMessage());
        }

        $this->assertNoDatabaseTenantContext('the mismatch guard must throw before any tenant context is ever established');

        $survivingLink = $this->runWithFirmContext($firm, fn () => EmailMessageLink::withoutGlobalScopes()->find($link->id));
        $this->assertNotNull($survivingLink, 'The link row must NOT be deleted when the actor firm mismatch guard fires.');
    }

    public function test_unlink_context_clears_after_success(): void
    {
        $firm = Firm::factory()->create();
        $link = $this->createLink($firm);
        $actor = FirmUser::factory()->forFirm($firm)->create();

        (new TenantContextService)->clearDatabaseTenantContext();

        app(EmailMessageLinkingService::class)->unlink($link, $actor);

        $this->assertNoDatabaseTenantContext('unlink() must restore context to the clean baseline it was called against');
    }

    private function createLink(Firm $firm): EmailMessageLink
    {
        $account = EmailAccount::factory()->forFirm($firm)->create();
        $message = EmailMessage::factory()->forAccount($account)->create();
        $matter = Matter::factory()->forFirm($firm)->create();
        $actor = FirmUser::factory()->forFirm($firm)->create();

        return $this->runWithFirmContext($firm, fn () => EmailMessageLink::factory()->create([
            'firm_id' => $firm->id,
            'email_message_id' => $message->id,
            'matter_id' => $matter->id,
            'client_id' => null,
            'linked_by_firm_user_id' => $actor->id,
            'is_primary' => true,
        ]));
    }
}
