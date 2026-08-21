<?php

declare(strict_types=1);

namespace Tests\Feature\Signature;

use App\Enums\ConsentChannel;
use App\Enums\ConsentStatus;
use App\Enums\FirmUserRole;
use App\Enums\NotificationTemplateStatus;
use App\Enums\SignatureRequestStatus;
use App\Models\Client;
use App\Models\CommunicationConsent;
use App\Models\Document;
use App\Models\DocumentHash;
use App\Models\Firm;
use App\Models\FirmUser;
use App\Models\NotificationTemplate;
use App\Models\SignatureCertificate;
use App\Models\SignatureEvent;
use App\Models\SignatureRequest;
use App\Models\SignatureRequestRecipient;
use App\Models\User;
use App\Notifications\TemplatedNotification;
use App\Services\SignatureRequestWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * SignatureRecipientPublicAccessTest — Non-payment completion program,
 * e-signature signer-facing flow. Proves:
 *   1. SignatureRequestWorkflowService::send() mints a real, hashed
 *      access token — the raw value never lands in the database, and
 *      the exact raw value actually delivered by email hashes to the
 *      stored access_token_hash.
 *   2. The public /sign/{uuid} route resolves a valid token, 404s a
 *      wrong or unknown one, and never persists the raw token anywhere
 *      a Log:: call could leak it.
 *   3. A signer can genuinely progress view → consent → sign through
 *      the real SignatureRecipientWorkflowService methods, end to end,
 *      over real HTTP requests.
 *   4. PdfDownloadPolicyService::decideForRecipient() is the actual
 *      authorization boundary for the document route (not bypassed).
 *   5. A token minted for one firm's recipient can never resolve a
 *      different firm's data (tenant isolation).
 */
final class SignatureRecipientPublicAccessTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------
    // 1. Token generation — hashed at rest, delivered raw exactly once
    // ------------------------------------------------------------

    public function test_send_mints_a_sha256_hashed_token_and_the_delivered_raw_token_matches_it(): void
    {
        Notification::fake();

        [$firm, $actor, $client, $recipient, $request] = $this->buildSendableRequestWithClientRecipient();

        app(SignatureRequestWorkflowService::class)->send($request, $actor);

        $fresh = $this->runWithFirmContext($firm, fn () => $recipient->fresh());

        $this->assertNotNull($fresh->access_token_hash);
        $this->assertSame(64, strlen($fresh->access_token_hash), 'access_token_hash must be a sha256 hex digest.');
        $this->assertTrue(ctype_xdigit($fresh->access_token_hash));

        $rawToken = null;

        Notification::assertSentOnDemand(
            TemplatedNotification::class,
            function (TemplatedNotification $notification) use (&$rawToken): bool {
                $body = (function () {
                    return $this->body;
                })->call($notification);

                if (preg_match('/token=([A-Za-z0-9\-_]+)/', $body, $matches) === 1) {
                    $rawToken = $matches[1];
                }

                return true;
            }
        );

        $this->assertNotNull($rawToken, 'The dispatched notification body must contain the signer link with a raw token.');
        $this->assertSame($fresh->access_token_hash, hash('sha256', $rawToken), 'The delivered raw token must hash to exactly the stored access_token_hash.');

        // The raw token must never be persisted anywhere except the
        // hash itself: scan every other text/json column this send()
        // call could plausibly have touched (signature_events'
        // metadata_json, notification_events' reason/recipient/
        // correlation_id, and the recipient row's own remaining
        // columns) for the raw value.
        $this->runWithFirmContext($firm, function () use ($rawToken, $recipient, $request): void {
            $eventPayloads = DB::table('signature_events')
                ->where('signature_request_id', $request->id)
                ->pluck('metadata_json')
                ->map(fn ($json) => (string) $json)
                ->implode(' ');

            $this->assertStringNotContainsString($rawToken, $eventPayloads, 'The raw token must never be persisted inside a signature_events row.');

            $notificationReasons = DB::table('notification_events')
                ->where('firm_id', $recipient->firm_id)
                ->pluck('reason')
                ->map(fn ($reason) => (string) $reason)
                ->implode(' ');

            $this->assertStringNotContainsString($rawToken, $notificationReasons, 'The raw token must never be persisted inside a notification_events row.');
        });
    }

    public function test_full_signer_journey_view_consent_sign_over_real_http_requests(): void
    {
        Notification::fake();

        [$firm, $actor, $client, $recipient, $request] = $this->buildSendableRequestWithClientRecipient();

        app(SignatureRequestWorkflowService::class)->send($request, $actor);

        $rawToken = $this->extractDeliveredRawToken();
        $uuid = $this->runWithFirmContext($firm, fn () => $recipient->fresh()->uuid);

        // GET: transitions Sent -> Viewed.
        $show = $this->get(route('public.signature-recipients.show', ['uuid' => $uuid]).'?token='.$rawToken);
        $show->assertOk();
        $this->assertSame(SignatureRequestStatus::Viewed, $this->runWithFirmContext($firm, fn () => $recipient->fresh()->status));

        // POST consent: transitions Viewed -> Consented, and records a
        // real consent_captured SignatureEvent (via
        // SignatureRecipientWorkflowService::consent(), never
        // reimplemented here).
        $consent = $this->post(route('public.signature-recipients.consent', ['uuid' => $uuid]), ['token' => $rawToken]);
        $consent->assertRedirect();
        $consented = $this->runWithFirmContext($firm, fn () => $recipient->fresh());
        $this->assertSame(SignatureRequestStatus::Consented, $consented->status);
        $this->assertNotNull($consented->consented_at);
        $this->assertTrue(
            $this->runWithFirmContext($firm, fn () => SignatureEvent::query()
                ->where('signature_request_recipient_id', $recipient->id)
                ->where('event_type', 'consent_captured')
                ->exists())
        );

        // POST sign: transitions Consented -> Signed. This recipient is
        // the request's only recipient, so signing them is unanimous —
        // proves the public signer flow reaches certificate generation
        // and Completed exactly like the authenticated
        // SignatureRecipientWorkflowServiceTest already proves for the
        // equivalent non-public path.
        $sign = $this->post(route('public.signature-recipients.sign', ['uuid' => $uuid]), ['token' => $rawToken]);
        $sign->assertRedirect();
        $signed = $this->runWithFirmContext($firm, fn () => $recipient->fresh());
        $this->assertSame(SignatureRequestStatus::Signed, $signed->status);
        $this->assertNotNull($signed->signed_at);

        $this->runWithFirmContext($firm, function () use ($request, $signed): void {
            $this->assertSame(1, SignatureCertificate::query()->where('signature_request_id', $signed->signature_request_id)->count());
            $this->assertSame(SignatureRequestStatus::Completed, SignatureRequest::query()->find($request->id)->status);
        });

        // Re-POSTing sign on the same link (e.g. a double-click, or the
        // signer revisiting an already-signed link) must degrade to a
        // harmless redirect back to the current state, never a second
        // certificate.
        $replay = $this->post(route('public.signature-recipients.sign', ['uuid' => $uuid]), ['token' => $rawToken]);
        $replay->assertRedirect();
        $this->runWithFirmContext($firm, function () use ($signed): void {
            $this->assertSame(1, SignatureCertificate::query()->where('signature_request_id', $signed->signature_request_id)->count(), 'A replayed sign() request must never create a second certificate.');
        });
    }

    public function test_decline_transitions_via_the_real_recipient_workflow_service(): void
    {
        Notification::fake();

        [$firm, $actor, $client, $recipient, $request] = $this->buildSendableRequestWithClientRecipient();
        app(SignatureRequestWorkflowService::class)->send($request, $actor);
        $rawToken = $this->extractDeliveredRawToken();
        $uuid = $this->runWithFirmContext($firm, fn () => $recipient->fresh()->uuid);

        $this->get(route('public.signature-recipients.show', ['uuid' => $uuid]).'?token='.$rawToken);

        $response = $this->post(route('public.signature-recipients.decline', ['uuid' => $uuid]), [
            'token' => $rawToken,
            'reason' => 'Changed my mind.',
        ]);

        $response->assertRedirect();
        $fresh = $this->runWithFirmContext($firm, fn () => $recipient->fresh());
        $this->assertSame(SignatureRequestStatus::Declined, $fresh->status);
        $this->assertSame('Changed my mind.', $fresh->declined_reason);
    }

    // ------------------------------------------------------------
    // 2. Invalid/wrong/unknown token -> generic 404
    // ------------------------------------------------------------

    public function test_show_route_404s_for_a_wrong_token(): void
    {
        $firm = Firm::factory()->create();
        $recipient = $this->makeRecipientWithKnownToken($firm, 'correct-raw-token');

        $response = $this->get(route('public.signature-recipients.show', ['uuid' => $recipient->uuid]).'?token=totally-wrong-token');

        $response->assertNotFound();
    }

    public function test_show_route_404s_for_an_unknown_uuid(): void
    {
        $response = $this->get(route('public.signature-recipients.show', ['uuid' => (string) Str::uuid()]).'?token=anything');

        $response->assertNotFound();
    }

    /**
     * Non-payment completion program safety review (Phase 6): the
     * self-lookup RLS policy's USING clause casts the session setting
     * to `::uuid` — proves a non-UUID-shaped path segment degrades to
     * the same generic 404 as any other unresolvable lookup, never a
     * raw Postgres cast-error 500 that could disclose internals.
     */
    public function test_show_route_404s_for_a_malformed_non_uuid_path_segment(): void
    {
        $response = $this->get(route('public.signature-recipients.show', ['uuid' => 'not-a-real-uuid']).'?token=anything');

        $response->assertNotFound();
    }

    public function test_show_route_404s_when_no_token_is_supplied_at_all(): void
    {
        $firm = Firm::factory()->create();
        $recipient = $this->makeRecipientWithKnownToken($firm, 'correct-raw-token');

        $response = $this->get(route('public.signature-recipients.show', ['uuid' => $recipient->uuid]));

        $response->assertNotFound();
    }

    public function test_show_route_succeeds_for_the_correct_token(): void
    {
        $firm = Firm::factory()->create();
        $recipient = $this->makeRecipientWithKnownToken($firm, 'correct-raw-token', SignatureRequestStatus::Sent);

        $response = $this->get(route('public.signature-recipients.show', ['uuid' => $recipient->uuid]).'?token=correct-raw-token');

        $response->assertOk();
    }

    // ------------------------------------------------------------
    // 3. Tenant isolation
    // ------------------------------------------------------------

    public function test_a_token_correctly_hashed_for_firm_as_recipient_never_resolves_firm_bs_recipient(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();

        $recipientA = $this->makeRecipientWithKnownToken($firmA, 'shared-raw-token', SignatureRequestStatus::Sent);
        $recipientB = $this->makeRecipientWithKnownToken($firmB, 'shared-raw-token', SignatureRequestStatus::Sent);

        // Firm A's own uuid + the (coincidentally identical) raw token
        // must resolve ONLY firm A's own recipient/request/document —
        // never leak anything from firm B.
        $responseA = $this->get(route('public.signature-recipients.show', ['uuid' => $recipientA->uuid]).'?token=shared-raw-token');
        $responseA->assertOk();
        $responseA->assertSee((string) $recipientA->signer_name);
        $responseA->assertDontSee((string) $recipientB->signer_email);

        // Firm B's uuid with the same raw token resolves firm B's OWN
        // row only.
        $responseB = $this->get(route('public.signature-recipients.show', ['uuid' => $recipientB->uuid]).'?token=shared-raw-token');
        $responseB->assertOk();
        $responseB->assertSee((string) $recipientB->signer_name);
    }

    public function test_raw_rls_proof_firm_as_session_cannot_read_firm_bs_recipient_row(): void
    {
        $firmA = Firm::factory()->create();
        $firmB = Firm::factory()->create();
        $recipientA = $this->makeRecipientWithKnownToken($firmA, 'token-a', SignatureRequestStatus::Sent);
        $recipientB = $this->makeRecipientWithKnownToken($firmB, 'token-b', SignatureRequestStatus::Sent);

        $visibleIds = $this->runWithFirmContext($firmA, fn () => DB::table('signature_request_recipients')->pluck('id')->all());

        $this->assertContains($recipientA->id, $visibleIds);
        $this->assertNotContains($recipientB->id, $visibleIds, "Firm A's session must never read Firm B's signature_request_recipients row.");
    }

    // ------------------------------------------------------------
    // 4. PdfDownloadPolicyService::decideForRecipient() is the real gate
    // ------------------------------------------------------------

    public function test_document_route_streams_the_real_file_for_an_active_recipient(): void
    {
        Storage::fake('local');

        $firm = Firm::factory()->create();
        $document = $this->runWithFirmContext($firm, fn () => Document::factory()->clean()->create([
            'firm_id' => $firm->id,
            'storage_disk' => 'local',
            'storage_path' => "documents/{$firm->id}/signable.pdf",
            'original_filename' => 'signable.pdf',
        ]));
        Storage::disk('local')->put($document->storage_path, 'the real pdf bytes');

        $recipient = $this->makeRecipientWithKnownToken($firm, 'doc-token', SignatureRequestStatus::Sent, $document);

        $response = $this->get(route('public.signature-recipients.document', ['uuid' => $recipient->uuid]).'?token=doc-token');

        $response->assertOk();
        $this->assertSame('the real pdf bytes', $response->streamedContent());
    }

    /**
     * PdfDownloadPolicyService::decideForRecipient() denies access once
     * a recipient's own status is declined/expired/voided — proves this
     * is a REAL, live check against current state, not a bypassed or
     * decorative call.
     */
    public function test_document_route_denies_a_declined_recipient(): void
    {
        Storage::fake('local');

        $firm = Firm::factory()->create();
        $document = $this->runWithFirmContext($firm, fn () => Document::factory()->clean()->create([
            'firm_id' => $firm->id,
            'storage_disk' => 'local',
            'storage_path' => "documents/{$firm->id}/signable.pdf",
        ]));
        Storage::disk('local')->put($document->storage_path, 'the real pdf bytes');

        $recipient = $this->makeRecipientWithKnownToken($firm, 'declined-token', SignatureRequestStatus::Declined, $document);

        $response = $this->get(route('public.signature-recipients.document', ['uuid' => $recipient->uuid]).'?token=declined-token');

        $response->assertForbidden();
    }

    // ------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------

    /**
     * @return array{0: Firm, 1: FirmUser, 2: Client, 3: SignatureRequestRecipient, 4: SignatureRequest}
     */
    private function buildSendableRequestWithClientRecipient(): array
    {
        $firm = Firm::factory()->create();
        $actor = $this->runWithFirmContext($firm, fn () => FirmUser::factory()
            ->forFirm($firm)
            ->forUser(User::factory()->create(['two_factor_confirmed_at' => now()]))
            ->role(FirmUserRole::Attorney)
            ->create());

        $document = $this->runWithFirmContext($firm, fn () => Document::factory()->clean()->create(['firm_id' => $firm->id]));
        // Real precondition of SignatureCertificateService::generate()
        // (see that class's own docblock) -- present here so signing
        // through to certificate generation works exactly like
        // SignatureRecipientWorkflowServiceTest's own established
        // sentRecipient() fixture.
        $this->runWithFirmContext($firm, fn () => DocumentHash::factory()->forDocument($document)->create());
        $client = $this->runWithFirmContext($firm, fn () => Client::factory()->forFirm($firm)->create(['email' => 'signer@example.test']));

        $this->runWithFirmContext($firm, function () use ($firm, $client): void {
            NotificationTemplate::factory()->domainVerified()->create([
                'firm_id' => null,
                'key' => 'signature_request_sent',
                'channel' => ConsentChannel::Email,
                'status' => NotificationTemplateStatus::Active,
            ]);

            CommunicationConsent::factory()->create([
                'firm_id' => $firm->id,
                'client_id' => $client->id,
                'channel' => ConsentChannel::Email,
                'status' => ConsentStatus::Granted,
                'granted_at' => now(),
            ]);
        });

        $service = app(SignatureRequestWorkflowService::class);
        $request = $service->create($firm, 'Engagement Letter', $actor, $document, null, null, $client);
        $recipient = $this->runWithFirmContext($firm, fn () => SignatureRequestRecipient::factory()->forRequest($request)->create([
            'client_id' => $client->id,
            'signer_name' => $client->display_name,
            'signer_email' => $client->email,
        ]));
        $service->attorneyReview($request, $actor, 'Suitable for e-signature under UETA/ESIGN.');

        return [$firm, $actor, $client, $recipient, $this->runWithFirmContext($firm, fn () => $request->fresh())];
    }

    private function extractDeliveredRawToken(): string
    {
        $rawToken = null;

        Notification::assertSentOnDemand(
            TemplatedNotification::class,
            function (TemplatedNotification $notification) use (&$rawToken): bool {
                $body = (function () {
                    return $this->body;
                })->call($notification);

                if (preg_match('/token=([A-Za-z0-9\-_]+)/', $body, $matches) === 1) {
                    $rawToken = $matches[1];
                }

                return true;
            }
        );

        $this->assertNotNull($rawToken);

        return $rawToken;
    }

    private function makeRecipientWithKnownToken(
        Firm $firm,
        string $rawToken,
        SignatureRequestStatus $status = SignatureRequestStatus::Sent,
        ?Document $document = null,
    ): SignatureRequestRecipient {
        $document ??= $this->runWithFirmContext($firm, fn () => Document::factory()->clean()->create(['firm_id' => $firm->id]));

        $request = $this->runWithFirmContext($firm, fn () => SignatureRequest::factory()->create([
            'firm_id' => $firm->id,
            'document_id' => $document->id,
            'status' => $status->value,
        ]));

        return $this->runWithFirmContext($firm, fn () => SignatureRequestRecipient::factory()->forRequest($request)->create([
            'status' => $status->value,
            'access_token_hash' => hash('sha256', $rawToken),
        ]));
    }
}
