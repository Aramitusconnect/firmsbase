<?php

declare(strict_types=1);

namespace App\Integrations\Exceptions;

use RuntimeException;

/**
 * ProviderOptionalOperationSuspendedException — thrown by
 * `App\Integrations\Billing\ProviderOperationPolicyResolver::resolve()`
 * (pipeline step 7) when the FIRM has suspended its own optional
 * operation for a given product/environment via its own row on
 * `provider_firm_operation_policies.optional_operation_suspended`
 * (checkpoint4-combined-design.md §9.4's `PlaidUsagePolicyPage` —
 * "a form-backed page... editing per-product optional-operation
 * suspension only" — the firm's own self-service surface over that
 * table, per §1.8's coordinator-resolved two-table split).
 *
 * Kept structurally distinct from `ProviderKillSwitchActiveException`
 * on purpose: a kill switch is a PLATFORM-admin-authored,
 * incident-response control the firm never writes to
 * (checkpoint4-design-cost-control.md §4.2 — "only a platform admin can
 * suspend a firm's optional operation via the kill-switch mechanism...
 * a firm cannot self-serve this"), while this exception represents the
 * firm's own, self-service opt-out of a supplementary (never core
 * Item-lifecycle or Transactions-sync) evidence source. Blending the
 * two into one exception/table would mix admin-only incident-response
 * state with per-firm self-service state on the same row, which is
 * exactly the kind of asymmetric-classification risk this mission's
 * RLS discipline (checkpoint4-combined-design.md §1.8) exists to avoid
 * — `provider_kill_switches` stays 100% Global/admin-authored with no
 * per-firm-writable row at all; only this table's own separate,
 * FORCE-RLS'd, firm-owned row can trip this exception.
 *
 * Only ever thrown for a classification whose
 * `ProviderBillingClassification::isOptional` is true — core Item
 * lifecycle/Transactions sync operations can never reach this branch,
 * since a firm suspending its own core sync would silently break the
 * connection's core function rather than merely opt out of a
 * supplementary evidence source.
 */
final class ProviderOptionalOperationSuspendedException extends RuntimeException
{
    public function __construct(
        public readonly string $providerKey,
        public readonly string $product,
        public readonly int $firmId,
    ) {
        parent::__construct(
            "Firm [id={$firmId}] has suspended its own optional operation for provider_key={$providerKey}, product={$product}."
        );
    }
}
