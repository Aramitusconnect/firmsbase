<?php

namespace App\Enums;

/**
 * WebhookDeliveryStatus — Exhausted is a distinct terminal state from a
 * single Failed attempt: it means max_attempts has been reached and no
 * further automatic retry will occur (correction #12). Only
 * WebhookReplayService can move a delivery's lineage forward past
 * Exhausted, and it does so by creating a brand-new delivery row, never
 * by changing this status on the original.
 */
enum WebhookDeliveryStatus: string
{
    case Pending = 'pending';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Exhausted = 'exhausted';
}
