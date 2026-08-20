<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Connection;

use App\Enums\AiMode;
use App\Enums\AiProvider;
use App\Models\AiUsageEvent;
use App\Models\Client;
use App\Models\Firm;
use App\Services\AiProviderConnectionTestService;
use App\Services\AiProviderKeyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Ai\Concerns\SetsUpAiEntitledFirm;
use Tests\TestCase;

/**
 * Test Connection exists so a firm finds out its credential is wrong HERE,
 * on a settings page, rather than from an intake that quietly stopped using
 * AI. That only holds if the probe walks the real path and if every provider
 * failure mode comes back as an actionable sentence instead of an exception,
 * a stack trace, or a reflected request that carries the API key.
 */
class AiProviderConnectionTestServiceTest extends TestCase
{
    use RefreshDatabase, SetsUpAiEntitledFirm;

    private function service(): AiProviderConnectionTestService
    {
        return app(AiProviderConnectionTestService::class);
    }

    private function firmWithCredential(): Firm
    {
        $firm = $this->makeAiEntitledFirm(AiMode::FirmOwned);
        app(AiProviderKeyService::class)->import($firm, AiProvider::OpenAi, 'test-key-not-a-real-credential');

        return $firm->fresh(['firmSettings', 'aiSettings']);
    }

    private function fakeStatus(int $status, array $body = []): void
    {
        Http::fake(['*/responses' => Http::response($body, $status)]);
    }

    private function fakeSuccess(): void
    {
        Http::fake(['*/responses' => Http::response([
            'output' => [['content' => [['text' => 'ok']]]],
            'usage' => ['input_tokens' => 8, 'output_tokens' => 2],
        ], 200)]);
    }

    public function test_a_working_credential_reports_success_and_names_the_model(): void
    {
        $firm = $this->firmWithCredential();
        $this->fakeSuccess();

        $result = $this->service()->test($firm);

        $this->assertTrue($result->succeeded);
        $this->assertSame('ok', $result->code);
        $this->assertSame('gpt-5.6-terra', $result->model);
    }

    public function test_the_probe_carries_no_client_matter_or_intake_data(): void
    {
        // The reason this test is specific rather than a smoke check: a
        // settings-page button is exactly the kind of thing that grows a
        // "give the model some context" argument later.
        $firm = $this->firmWithCredential();
        $client = Client::factory()->create(['firm_id' => $firm->id]);
        $this->fakeSuccess();

        $this->service()->test($firm);

        Http::assertSent(function ($request) use ($client, $firm) {
            $body = json_encode($request->data());

            // Matched on names rather than ids: a bare integer id collides with
            // digits that legitimately appear in the model name and the token
            // cap, which would make this assertion pass or fail by accident.
            $this->assertStringNotContainsString($client->display_name, $body);
            $this->assertStringNotContainsString($client->email, $body);
            $this->assertStringNotContainsString($firm->name, $body);
            $this->assertStringNotContainsString('test-key-not-a-real-credential', $body, 'The credential travels in the header, never the body.');

            $contents = array_column($request->data()['input'], 'content');
            $this->assertSame(
                ['FirmsVault connection test. Reply with the single word: ok.', 'ping'],
                $contents,
                'The probe must be the fixed diagnostic string and nothing else.',
            );

            return true;
        });
    }

    public function test_a_rejected_key_is_reported_as_a_credential_problem(): void
    {
        $firm = $this->firmWithCredential();
        $this->fakeStatus(401, ['error' => ['message' => 'Incorrect API key provided: sk-abc123']]);

        $result = $this->service()->test($firm);

        $this->assertFalse($result->succeeded);
        $this->assertSame('invalid_credential', $result->code);
        $this->assertSame(401, $result->status);
        $this->assertStringNotContainsString('sk-abc123', $result->message, 'A provider body must never reach the firm verbatim.');
    }

    public function test_a_forbidden_key_is_distinguished_from_a_rejected_one(): void
    {
        $firm = $this->firmWithCredential();
        $this->fakeStatus(403);

        $result = $this->service()->test($firm);

        $this->assertSame('access_denied', $result->code);
        $this->assertSame(403, $result->status);
    }

    public function test_an_unavailable_model_is_reported_as_such(): void
    {
        $firm = $this->firmWithCredential();
        $this->fakeStatus(404);

        $result = $this->service()->test($firm);

        $this->assertSame('model_unavailable', $result->code);
    }

    public function test_a_rate_limit_is_reported_as_temporary(): void
    {
        $firm = $this->firmWithCredential();
        $this->fakeStatus(429);

        $result = $this->service()->test($firm);

        $this->assertSame('rate_limited', $result->code);
    }

    public function test_a_provider_outage_is_reported_without_a_stack_trace(): void
    {
        $firm = $this->firmWithCredential();
        $this->fakeStatus(503);

        $result = $this->service()->test($firm);

        $this->assertSame('provider_error', $result->code);
        $this->assertSame(503, $result->status);
    }

    public function test_a_timeout_is_reported_rather_than_thrown(): void
    {
        $firm = $this->firmWithCredential();
        Http::fake(['*/responses' => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);

        $result = $this->service()->test($firm);

        $this->assertFalse($result->succeeded);
        $this->assertSame('timeout', $result->code);
        $this->assertStringNotContainsString('cURL', $result->message);
    }

    public function test_a_two_hundred_with_an_unreadable_body_is_not_reported_as_success(): void
    {
        $firm = $this->firmWithCredential();
        $this->fakeStatus(200, ['unexpected' => 'shape']);

        $result = $this->service()->test($firm);

        $this->assertFalse($result->succeeded, 'A 200 that FirmsVault cannot read is not a working connection.');
        $this->assertSame('malformed_response', $result->code);
    }

    public function test_a_firm_that_is_not_firm_owned_is_told_so_without_any_request(): void
    {
        $firm = $this->makeAiEntitledFirm(AiMode::Disabled);
        Http::fake();

        $result = $this->service()->test($firm);

        $this->assertFalse($result->succeeded);
        $this->assertSame('not_firm_owned', $result->code);
        Http::assertNothingSent();
    }

    public function test_a_firm_with_no_credential_is_told_so_without_any_request(): void
    {
        $firm = $this->makeAiEntitledFirm(AiMode::FirmOwned);
        Http::fake();

        $result = $this->service()->test($firm);

        $this->assertSame('no_active_credential', $result->code);
        Http::assertNothingSent();
    }

    public function test_a_revoked_credential_cannot_be_tested(): void
    {
        $firm = $this->firmWithCredential();
        app(AiProviderKeyService::class)->revoke($firm, AiProvider::OpenAi);
        Http::fake();

        $result = $this->service()->test($firm);

        $this->assertFalse($result->succeeded);
        $this->assertSame('no_active_credential', $result->code);
        Http::assertNothingSent();
    }

    public function test_a_firm_at_its_budget_cannot_spend_more_through_this_button(): void
    {
        $firm = $this->firmWithCredential();
        Http::fake();

        $this->runWithFirmContext($firm, function () use ($firm) {
            $firm->aiSettings->update(['token_limit_per_period' => 10]);

            AiUsageEvent::factory()->forFirm($firm)->create([
                'tokens_in' => 10,
                'tokens_out' => 0,
                'cost_cents' => 0,
            ]);
        });

        $result = $this->service()->test($firm->fresh(['aiSettings']));

        $this->assertFalse($result->succeeded);
        $this->assertSame('budget_exceeded', $result->code);
        Http::assertNothingSent();
    }

    public function test_a_successful_probe_is_recorded_against_the_firms_usage_when_an_actor_is_known(): void
    {
        // The probe spends real tokens. Leaving it out of the usage log would
        // make the budget a ceiling with a hole in it.
        $firm = $this->firmWithCredential();
        $actor = $this->makeFirmOwner($firm);
        $this->fakeSuccess();

        $this->service()->test($firm, $actor->user);

        $event = $this->runWithFirmContext($firm, fn () => AiUsageEvent::query()->where('firm_id', $firm->id)->first());

        $this->assertNotNull($event);
        $this->assertSame('connection_test', $event->action_type->value);
        $this->assertSame(8, $event->tokens_in);
    }
}
