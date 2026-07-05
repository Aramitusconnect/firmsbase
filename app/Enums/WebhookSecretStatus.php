<?php

namespace App\Enums;

/**
 * WebhookSecretStatus — old secrets are rotated, never deleted
 * (correction #13/#8). A Rotated row remains in the table forever so
 * historical webhook_delivery_attempts.webhook_secret_id references
 * stay explainable.
 */
enum WebhookSecretStatus: string
{
    case Active = 'active';
    case Rotated = 'rotated';
}
