<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Concerns;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Fake the OpenAI transport, not the adapter.
 *
 * There is no application-level fake provider any more, and AiProviderResolver
 * builds OpenAiProviderAdapter directly rather than resolving it from the
 * container — so container binding no longer intercepts anything. The honest
 * seam is the HTTP boundary, and faking there exercises the real adapter,
 * the real payload and the real response parsing. No credits are spent.
 *
 * One stub is registered per test and the behaviour it serves is read from
 * these properties at request time. Http::fake() APPENDS stubs and the first
 * match wins, so a second fake() call in a test body would be silently
 * ignored — mutating state that a single stub reads is the only way an
 * override actually takes effect.
 */
trait FakesOpenAiTransport
{
    private bool $openAiStubRegistered = false;

    private ?string $openAiQuestionCode = null;

    private ?string $openAiExtractedValue = null;

    private bool $openAiTransportFails = false;

    /**
     * Both arguments default to "behave correctly": target whichever question
     * the service asked for, and return the visitor's own words. Pass either
     * one to simulate a misbehaving model.
     */
    protected function fakeOpenAiExtraction(?string $questionCode = null, ?string $extractedValue = null): void
    {
        $this->openAiQuestionCode = $questionCode;
        $this->openAiExtractedValue = $extractedValue;
        $this->openAiTransportFails = false;

        $this->registerOpenAiStub();
    }

    /**
     * Simulate a provider that never answers — the timeout path.
     */
    protected function fakeOpenAiTransportFailure(): void
    {
        $this->openAiTransportFails = true;

        $this->registerOpenAiStub();
    }

    private function registerOpenAiStub(): void
    {
        if ($this->openAiStubRegistered) {
            return;
        }

        $this->openAiStubRegistered = true;

        Http::fake([
            '*/responses' => function ($request) {
                if ($this->openAiTransportFails) {
                    throw new ConnectionException('simulated timeout');
                }

                $payload = $request->data();

                // The question the service targeted travels in the system
                // message's EXTRACT_FIELD trigger; the visitor's text is the
                // user message. Echoing them back is what a correct extraction
                // looks like.
                $system = (string) ($payload['input'][0]['content'] ?? '');
                $user = (string) ($payload['input'][1]['content'] ?? '');

                return Http::response([
                    'output' => [['content' => [['text' => json_encode([
                        'question_code' => $this->openAiQuestionCode ?? trim(str_replace('EXTRACT_FIELD:', '', $system)),
                        'extracted_value' => $this->openAiExtractedValue ?? $user,
                    ])]]]],
                    'usage' => ['input_tokens' => 12, 'output_tokens' => 9],
                ], 200);
            },
        ]);
    }
}
