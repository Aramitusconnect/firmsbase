<?php

declare(strict_types=1);

namespace App\Integrations\Enums;

/**
 * ResourceType — a small, deliberately generic, provider-neutral
 * catalog of the kinds of external objects an integration provider may
 * pull/push (SupportsPullSyncContract/SupportsPushSyncContract). These
 * cases are domain-shape vocabulary only (e.g. what a legal-industry
 * SaaS commonly synchronizes with third-party systems), not tied to
 * any specific real provider's API — no Google/Microsoft/Stripe/Plaid/
 * LawPay/QuickBooks/Clio/Zoom/Dropbox naming or shape is encoded here.
 *
 * Capability-contract methods (`pull()`, `push()`, etc.) take
 * `resourceType` as a plain string, not this enum directly, so a
 * resource type unknown to this enum can still be exercised by a
 * provider without a framework change — this enum exists only to give
 * ProviderMetadata/TestProvider a stable, documented starting
 * vocabulary. Reviewer note: the exact case list is a Checkpoint 1
 * design judgment call (Stage A's provider-contracts.md did not freeze
 * specific cases), chosen to be broad enough to exercise the whole
 * framework via TestProvider without implying preparation for any
 * particular real-provider integration.
 */
enum ResourceType: string
{
    case Contact = 'contact';
    case CalendarEvent = 'calendar_event';
    case Document = 'document';
    case Task = 'task';
    case Message = 'message';
    case Invoice = 'invoice';
    case Payment = 'payment';
}
