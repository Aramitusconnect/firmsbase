<?php

declare(strict_types=1);

namespace App\Integrations\Contracts;

/**
 * RequiresBillableCallPipelineContract — new, empty marker contract
 * (checkpoint4-design-cost-control.md §2.1; checkpoint4-combined-design.md
 * §8.3, resolving Finding 1 of checkpoint4-security-review.md).
 *
 * Every shared job/service call site that wraps a provider method in
 * `App\Integrations\Support\OutboundProviderHttpClient::execute()`
 * (`PullSyncJob::runBatchLoop()`, `PushSyncJob::run()`,
 * `ProviderConnectionService::bootstrapWebhookSubscriptions()`,
 * `RenewGraphSubscriptionJob`) checks `$provider instanceof
 * RequiresBillableCallPipelineContract` before additionally routing
 * that same call through
 * `App\Integrations\Billing\ProviderBillableCallPipeline::execute()` —
 * so every edit those call sites eventually need is an additive
 * `instanceof` branch, never a removed or restructured one, and
 * `Microsoft365Provider`'s, `GoogleWorkspaceProvider`'s, and
 * `TestProvider`'s existing pull/push/subscribe/renew paths stay
 * provably unchanged. Mirrors the existing `instanceof Supports*Contract`
 * composition discipline this codebase already uses everywhere else.
 *
 * Deliberately zero methods — this contract carries no capability of
 * its own, only a routing signal. No class in this checkpoint's
 * cost-control track implements it; the Plaid provider-core track is
 * the one implementer, decoupling this contract's existence (and the
 * pipeline it gates) from whether `PlaidProvider` has been built yet.
 * The actual call-site wiring (the `instanceof` checks themselves) is
 * a separate, later cross-cutting integration pass — see
 * checkpoint4-design-cost-control.md §2.1's file-by-file table.
 */
interface RequiresBillableCallPipelineContract {}
