<?php

declare(strict_types=1);

namespace App\Ai\OpenAi;

/**
 * The provider failure taxonomy the UI is allowed to show.
 *
 * Deliberately coarse and secret-free: every case maps to a message a firm
 * administrator can act on, and none of them can carry an Authorization header,
 * a key fragment, or a raw provider trace. Anything unrecognised becomes
 * ProviderError rather than leaking an upstream string.
 */
enum OpenAiFailureReason: string
{
    case InvalidCredential = 'invalid_credential';
    case AccessDenied = 'access_denied';
    case RateLimited = 'rate_limited';
    case ModelUnavailable = 'model_unavailable';
    case Timeout = 'timeout';
    case ProviderError = 'provider_error';
    case MalformedResponse = 'malformed_response';
    case SchemaViolation = 'schema_violation';

    public static function fromStatus(int $status): self
    {
        return match (true) {
            $status === 401 => self::InvalidCredential,
            $status === 403 => self::AccessDenied,
            $status === 404 => self::ModelUnavailable,
            $status === 429 => self::RateLimited,
            $status >= 500 => self::ProviderError,
            default => self::ProviderError,
        };
    }

    /**
     * Firm-facing copy. Actionable, and safe to render verbatim.
     */
    public function message(): string
    {
        return match ($this) {
            self::InvalidCredential => 'The API key was rejected by OpenAI. Check the key and save it again.',
            self::AccessDenied => 'OpenAI denied access for this key. Confirm the key\'s project has API access enabled.',
            self::RateLimited => 'OpenAI rate limit or quota reached. Try again shortly, or check the billing quota on your OpenAI account.',
            self::ModelUnavailable => 'The configured model is not available to this API key\'s project.',
            self::Timeout => 'OpenAI did not respond in time. Intake continues without AI assistance.',
            self::ProviderError => 'OpenAI returned an error. Intake continues without AI assistance.',
            self::MalformedResponse => 'OpenAI returned a response FirmsVault could not read.',
            self::SchemaViolation => 'OpenAI returned a response that did not match the expected intake format.',
        };
    }
}
