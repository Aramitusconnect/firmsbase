<?php

declare(strict_types=1);

namespace App\Ai\OpenAi;

use App\Enums\AiProvider;
use App\Services\AiProviderAdapterInterface;
use App\Services\AiStructuredOutputSchemaRegistry;
use App\ValueObjects\AiPromptRequest;
use App\ValueObjects\AiProviderResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;

/**
 * The first real AI provider: OpenAI, via the Responses API.
 *
 * Lives under App\Ai rather than App\Integrations deliberately. Everything
 * under app/Integrations belongs to the integration-provider subsystem, whose
 * firewall (tests/Unit/Integrations/NoRealNetworkCallTest) requires every
 * network call there to go through ProviderRequestExecutor — the registry,
 * credential and retry machinery this adapter is not part of and does not use.
 * Putting an AI adapter there would have meant widening that firewall's
 * exemption list, weakening a real invariant to accommodate a file that simply
 * belonged somewhere else.
 *
 * This is the ONLY place OpenAI request/response shapes exist. The marketplace
 * assistant, the answer service, conflict checking and conversion all speak
 * AiPromptRequest/AiProviderResponse and must stay unaware that OpenAI is
 * behind them — that is what keeps a second provider from becoming a rewrite.
 *
 * Structured output is mandatory for intake extraction. The Responses API's
 * `text.format` with `strict: true` is what makes the model's reply a schema
 * conformance problem rather than a parsing problem, so the caller never has to
 * guess at free text. A reply that still fails to decode is treated as a
 * provider failure, not silently coerced.
 *
 * The credential is passed per-call and never stored on the instance: the
 * adapter is resolved fresh per firm by AiProviderResolver, and a long-lived
 * object holding a decrypted secret is exactly what the key architecture exists
 * to avoid.
 */
final readonly class OpenAiProviderAdapter implements AiProviderAdapterInterface
{
    public function __construct(
        #[SensitiveParameter] private string $apiKey,
        private string $model,
        private string $baseUri,
        private int $timeoutSeconds,
        private int $connectTimeoutSeconds,
        private int $maxOutputTokens,
    ) {}

    public function generate(AiPromptRequest $request): AiProviderResponse
    {
        $payload = [
            'model' => $this->model,
            // Bounded output: an intake turn returns one structured field, and
            // an unbounded generation is both a cost risk and a latency risk.
            'max_output_tokens' => $this->maxOutputTokens,
            'input' => [
                ['role' => 'system', 'content' => $request->instructionText],
                ['role' => 'user', 'content' => $this->userContent($request)],
            ],
        ];

        // Only constrain output when the caller named a schema. An unconstrained
        // turn is still valid for summarisation; extraction always names one.
        $schemaKey = $request->responseSchemaKey;

        if ($schemaKey !== null && AiStructuredOutputSchemaRegistry::has($schemaKey)) {
            $payload['text'] = [
                'format' => [
                    'type' => 'json_schema',
                    'name' => $schemaKey,
                    'strict' => true,
                    'schema' => OpenAiStructuredSchema::forRegistryKey($schemaKey),
                ],
            ];
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout($this->timeoutSeconds)
                ->connectTimeout($this->connectTimeoutSeconds)
                ->acceptJson()
                ->asJson()
                ->post(rtrim($this->baseUri, '/').'/responses', $payload);
        } catch (ConnectionException $e) {
            // Never surface $e->getMessage() upward verbatim: connection
            // exceptions can echo the request, and the request carries the
            // Authorization header.
            throw new OpenAiProviderException(OpenAiFailureReason::Timeout);
        }

        if ($response->failed()) {
            throw new OpenAiProviderException(
                OpenAiFailureReason::fromStatus($response->status()),
                $response->status(),
            );
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new OpenAiProviderException(OpenAiFailureReason::MalformedResponse);
        }

        $outputText = $this->extractOutputText($body);

        if ($outputText === null) {
            throw new OpenAiProviderException(OpenAiFailureReason::MalformedResponse);
        }

        $structured = null;

        if ($schemaKey !== null) {
            $decoded = json_decode($outputText, true);

            // strict:true should make this unreachable, but a schema violation
            // must fail closed rather than reach the answer service as garbage.
            if (! is_array($decoded)) {
                throw new OpenAiProviderException(OpenAiFailureReason::SchemaViolation);
            }

            $structured = $decoded;
        }

        return new AiProviderResponse(
            outputText: $outputText,
            tokensIn: (int) data_get($body, 'usage.input_tokens', 0),
            tokensOut: (int) data_get($body, 'usage.output_tokens', 0),
            requestedToolActions: [],
            structuredOutput: $structured,
        );
    }

    public function provider(): AiProvider
    {
        return AiProvider::OpenAi;
    }

    public function model(): string
    {
        return $this->model;
    }

    /**
     * The worst-case output size for one turn. The budget preflight reserves
     * against this rather than guessing what the model will actually return.
     */
    public function maxOutputTokens(): int
    {
        return $this->maxOutputTokens;
    }

    /**
     * Document-derived text arrives already wrapped as untrusted data by
     * PromptInjectionResistanceService; it is concatenated, never merged into
     * the system role, so prospect content cannot become instructions.
     */
    private function userContent(AiPromptRequest $request): string
    {
        if ($request->documentDerivedText === null) {
            return '';
        }

        return $request->documentDerivedText;
    }

    /**
     * Responses API returns content parts; the structured/text payload is the
     * first output message's first content part.
     *
     * @param  array<string, mixed>  $body
     */
    private function extractOutputText(array $body): ?string
    {
        $direct = data_get($body, 'output.0.content.0.text');

        if (is_string($direct) && $direct !== '') {
            return $direct;
        }

        // Some responses place a convenience aggregate alongside `output`.
        $aggregate = data_get($body, 'output_text');

        return is_string($aggregate) && $aggregate !== '' ? $aggregate : null;
    }
}
