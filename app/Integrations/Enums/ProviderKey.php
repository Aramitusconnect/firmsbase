<?php

declare(strict_types=1);

namespace App\Integrations\Enums;

/**
 * ProviderKey — the closed, code-defined registry of integration
 * provider identities. Distinct from, and unrelated to, the existing
 * App\Enums\IntegrationType (a closed Phase-16 enum for Stripe/
 * EmailProvider/VirusScanning/Telemetry degradation modes) — never
 * reused, extended, or collided with here.
 *
 * Checkpoint 1 (checkpoint-00-final-specification.md §6/§8/§21)
 * registers exactly one concrete value, `Test`, backing the internal
 * `App\Integrations\Providers\TestProvider\TestProvider` stub — the
 * only provider implemented anywhere in this mission. No real provider
 * key (google/microsoft/quickbooks/lawpay/clio/stripe/plaid/zoom/
 * dropbox, per provider-contracts.md's Stage A registry design) is
 * pre-registered here, since doing so before any such provider exists
 * could read as preparing to enable them — deliberately out of scope.
 *
 * A firm's stored provider reference is always looked up against this
 * code-defined registry (via ProviderRegistry), never used to
 * dynamically instantiate an arbitrary class or construct an arbitrary
 * API call.
 *
 * FirmsVault Live Integrations, Checkpoint 2 addition
 * (checkpoint2-combined-design.md §1.1, P-1): `Microsoft365` is the
 * first real (non-`Test`) provider key registered in this enum. Value
 * `'microsoft365'` — not `'microsoft'`, not `'m365'`, not
 * `'office365'` — matches the exact string already used in this
 * mission's own design documents and config illustrative comments, and
 * is stable/future-proof against a later, distinct
 * `ProviderKey::GoogleWorkspace` case (no short-form naming collision
 * risk). Adding this case is step one of this checkpoint — nothing
 * else (config wiring, catalog seed, `Microsoft365Provider` itself)
 * can proceed without it. The class implementing this key
 * (`App\Integrations\Providers\Microsoft365\Microsoft365Provider`)
 * does not exist yet as of this change — see
 * `config('integrations.providers')`'s env-gated, class-existence-
 * tolerant registration comment for why that is safe.
 *
 * FirmsVault Live Integrations, Checkpoint 3 addition
 * (checkpoint3-combined-design.md §1.1, the reconciled/binding value):
 * `GoogleWorkspace`. Value `'googleworkspace'` — full compound provider
 * name, lowercase, zero separator — an exact structural match to
 * `'microsoft365'` immediately above, NOT `'google_workspace'` (an
 * underscored form one of this checkpoint's three independent design
 * passes originally proposed but which the combined-design reconciliation
 * rejected as inconsistent with this enum's own existing naming rule).
 * The class implementing this key
 * (`App\Integrations\Providers\GoogleWorkspace\GoogleWorkspaceProvider`)
 * is built as a parallel, disjoint change in this same checkpoint — see
 * `config('integrations.providers')`'s identical class-existence-tolerant
 * registration comment.
 *
 * FirmsVault Live Integrations, Checkpoint 4 addition
 * (checkpoint4-combined-design.md §1.2/§6.1, confirmed identically
 * across all four Checkpoint 4 source docs): `Plaid`. Value `'plaid'` —
 * lowercase, zero separator, matching `'microsoft365'`/`'googleworkspace'`'s
 * own established convention exactly. The class implementing this key
 * (`App\Integrations\Providers\Plaid\PlaidProvider`) is a parallel,
 * disjoint change in this same checkpoint — see
 * `config('integrations.providers')`'s identical class-existence-tolerant
 * registration comment.
 */
enum ProviderKey: string
{
    case Test = 'test';
    case Microsoft365 = 'microsoft365';
    case GoogleWorkspace = 'googleworkspace';
    case Plaid = 'plaid';
}
