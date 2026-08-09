<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    // SES event consumer (feature/ses-event-consumer). 'key'/'secret'
    // both default to null, identical in intent to the 'ses' block
    // above — the ECS task role's own temporary credentials are used
    // via the AWS SDK's default credential provider chain. Never set
    // AWS_ACCESS_KEY_ID/AWS_SECRET_ACCESS_KEY as static long-lived
    // credentials for this queue.
    'ses_events' => [
        'queue_url' => env('SES_EVENTS_QUEUE_URL'),
        'region' => env('SES_EVENTS_QUEUE_REGION', env('AWS_DEFAULT_REGION', 'us-east-1')),
        'key' => env('SES_EVENTS_AWS_ACCESS_KEY_ID'),
        'secret' => env('SES_EVENTS_AWS_SECRET_ACCESS_KEY'),
        'wait_time_seconds' => (int) env('SES_EVENTS_WAIT_TIME_SECONDS', 20),
        'visibility_timeout_seconds' => (int) env('SES_EVENTS_VISIBILITY_TIMEOUT_SECONDS', 60),
        'max_messages' => (int) env('SES_EVENTS_MAX_MESSAGES', 10),
    ],

    // SES event consumer remediation (post-578ee98 audit, finding H1).
    // recipient_fingerprint_hmac_key is a NEW, DEDICATED, platform-wide
    // secret for PlatformNotificationCorrelationService's keyed
    // HMAC-SHA256 lookup — mirrors
    // integrations.oauth_apps.googleworkspace.gmail_mailbox_routing_hmac_key's
    // own "WHY A KEYED HMAC, NOT A PLAIN HASH" discipline exactly.
    // Never APP_KEY, never reused across purposes, fail-closed if
    // missing (see PlatformNotificationCorrelationService::hmacKey()).
    'platform_notifications' => [
        'recipient_fingerprint_hmac_key' => env('PLATFORM_NOTIFICATIONS_RECIPIENT_FINGERPRINT_HMAC_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Payment-Channel Safety Hardening pass, item 1. Read only by
    // App\Services\Stripe\PaymentGatewaySimulationPolicyService — this
    // flag can NEVER enable simulation by itself; it only takes effect
    // when app()->environment('local') is also true (see that service's
    // own docblock). In `testing`, simulation is always on regardless
    // of this value; in `staging`/`production`, it is always off
    // regardless of this value — there is no way to set this env var
    // to make a staging/production box silently accept a fake payment.
    'stripe' => [
        'gateway_simulation_enabled' => (bool) env('PAYMENT_GATEWAY_SIMULATION_ENABLED', false),
    ],

];
