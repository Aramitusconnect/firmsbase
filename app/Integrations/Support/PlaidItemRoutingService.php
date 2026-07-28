<?php

declare(strict_types=1);

namespace App\Integrations\Support;

use App\Integrations\Data\ResolvedPlaidItemRoute;
use App\Integrations\Models\FirmIntegration;
use App\Services\EmailBodyEncryptionService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * PlaidItemRoutingService — FirmsVault Live Integrations, Checkpoint 4
 * (checkpoint4-design-plaid-provider-core.md §11.2;
 * checkpoint4-combined-design.md §1.1.1, binding "Option B";
 * checkpoint4-security-review.md Finding 7, confirmed safe/sufficient).
 * The SOLE writer/reader of the new, dedicated
 * `integration_plaid_item_routes` table — a closed writer discipline
 * mirroring `integration_gmail_mailbox_routes`'s own documented "named
 * writers only" convention exactly (see that table's migration
 * docblock, and `App\Integrations\Support\GmailMailboxRoutingService`,
 * the direct structural precedent this class mirrors byte-for-byte).
 * No other class may write or delete rows in this table.
 *
 * WHY THIS TABLE/SERVICE EXISTS AT ALL, RATHER THAN REUSING
 * `integration_webhook_routing_index`: Plaid's `item_id` arrives in
 * every webhook body with no clientState/channel-token equivalent to
 * round-trip. `item_id`'s real entropy is not officially documented by
 * Plaid (disclosed only as "an identifier... value must be considered
 * opaque") — treated with the same caution Checkpoint 3 applied to
 * Gmail's mailbox address: a provider-assigned identifier of unverified
 * entropy is never trusted as a lookup key the way a FirmsVault-issued
 * CSPRNG token is. See `database/migrations/*_create_integration_plaid_item_routes_table.php`
 * for the full "WHY THIS TABLE HAS NO RLS" reasoning.
 *
 * WHY A KEYED HMAC, NOT A PLAIN HASH: identical reasoning to
 * `GmailMailboxRoutingService`'s own docblock, applied to `item_id`
 * instead of a mailbox address — `item_lookup_hmac` is a KEYED
 * HMAC-SHA256, keyed by a new, dedicated, platform-wide secret
 * (`config('integrations.oauth_apps.plaid.item_routing_hmac_key')`,
 * generated once via `random_bytes(32)` at provisioning time — never
 * derived from `APP_KEY`, never reused across purposes, and NEVER
 * `App\Services\EmailBodyEncryptionService`'s own per-firm keys, which
 * are the wrong shape for a lookup that must succeed BEFORE any firm
 * context exists on the inbound request).
 *
 * WHY NO RLS ON THE UNDERLYING TABLE (mirrors
 * `integration_webhook_routing_index`/`integration_gmail_mailbox_routes`'s
 * own "WHY THIS TABLE HAS NO RLS" docblock convention — see this
 * service's owning migration for the authoritative, full-length version
 * of this reasoning): `resolveByItemId()` below is the one
 * pre-tenant-context read this whole feature depends on — an inbound
 * Plaid webhook delivery carries no firm identity at all until this
 * lookup resolves one. A FORCE RLS policy keyed on
 * `app.current_firm_id` would make that lookup structurally impossible.
 * This table therefore carries no RLS and no FORCE ROW LEVEL SECURITY,
 * a deliberate, disclosed, coordinator-reviewed exception — never
 * something a future engineer should "fix" by adding a policy here.
 *
 * Every write below is deliberately NOT wrapped in this class's own
 * `DB::transaction()` — `route()`'s delete-then-insert pair and
 * `unroute()`'s single delete are both meant to be called from INSIDE
 * the caller's own already-open transaction
 * (`PlaidProvider::subscribe()`, reached from
 * `ProviderConnectionService`'s own connect/disconnect/renewal
 * transaction), exactly mirroring `GmailMailboxRoutingService`'s
 * identical, already-established discipline.
 */
final class PlaidItemRoutingService
{
    public function __construct(private readonly EmailBodyEncryptionService $encryption) {}

    /**
     * The SOLE writer. Deletes any existing row for `$connection->id`
     * first (mirrors `ProviderConnectionService::enableWebhookRouting()`'s
     * own "delete before insert, never `updateOrCreate()`" discipline,
     * so a connection can never accumulate more than one resolvable
     * item route), then inserts the new row: a keyed HMAC lookup value
     * plus an encrypted display value (via the SAME
     * `EmailBodyEncryptionService` pattern
     * `App\Integrations\Support\GmailMailboxRoutingService`/
     * `App\Integrations\Services\SyncCursorService` already use — never
     * a second encryption system).
     *
     * The `item_lookup_hmac` UNIQUE constraint means a second,
     * DIFFERENT connection attempting to route the SAME item_id fails
     * here at the DB layer — a real, catchable
     * `Illuminate\Database\QueryException`, deliberately allowed to
     * propagate UNCAUGHT (never a silent overwrite of the first
     * connection's route) so the caller's own ambient transaction rolls
     * back the whole operation rather than degrading silently.
     *
     * $itemId MUST be sourced from an authenticated, first-party Plaid
     * API response (`/item/public_token/exchange`'s own `item_id` field —
     * see PlaidProvider::exchangePublicToken()/subscribe()) — NEVER an
     * unverified inbound webhook body field. This method itself does not
     * and cannot enforce that provenance; it is the caller's
     * responsibility.
     */
    public function route(FirmIntegration $connection, string $itemId): void
    {
        $normalized = $this->normalizeItemId($itemId);
        $hmac = $this->hmacFor($normalized);

        $result = $this->encryption->encrypt($connection->firm, $normalized);

        if (! $result->succeeded) {
            throw new RuntimeException(
                "PlaidItemRoutingService::route() could not encrypt the item_id display value for connection {$connection->id}: {$result->reason}"
            );
        }

        DB::table('integration_plaid_item_routes')
            ->where('firm_integration_id', $connection->id)
            ->delete();

        DB::table('integration_plaid_item_routes')->insert([
            'firm_id' => $connection->firm_id,
            'firm_integration_id' => $connection->id,
            'integration_provider_id' => $connection->integration_provider_id,
            'item_lookup_hmac' => $hmac,
            'item_display_ciphertext' => $result->ciphertext,
            'item_display_encryption_key_id' => $result->encryptionKeyId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * The SOLE deleter. Deletes every row for `$connection->id` (there
     * is at most one, by construction of route()) — idempotent, safe to
     * call on a connection with no mapping. Mirrors
     * `ProviderConnectionService::disableWebhookRouting()`'s own
     * `->where('firm_integration_id', ...)->delete()` shape exactly.
     */
    public function unroute(FirmIntegration $connection): void
    {
        DB::table('integration_plaid_item_routes')
            ->where('firm_integration_id', $connection->id)
            ->delete();
    }

    /**
     * The SOLE reader — the pre-tenant-context lookup this whole
     * feature exists to serve. Anti-enumeration / collapse-to-null
     * discipline, matching every other pre-tenant-context resolver in
     * this codebase: returns null for EVERY non-usable case (empty
     * item_id, unknown item_id) — NEVER throws, so a caller can never
     * distinguish "malformed input" from "no such route" by exception
     * type alone.
     */
    public function resolveByItemId(string $itemId): ?ResolvedPlaidItemRoute
    {
        $normalized = trim($itemId);

        if ($normalized === '') {
            return null;
        }

        $hmac = $this->hmacFor($normalized);

        $row = DB::table('integration_plaid_item_routes')
            ->where('item_lookup_hmac', $hmac)
            ->first();

        if ($row === null) {
            return null;
        }

        return new ResolvedPlaidItemRoute(
            firmId: (int) $row->firm_id,
            firmIntegrationId: (int) $row->firm_integration_id,
            integrationProviderId: (int) $row->integration_provider_id,
        );
    }

    /**
     * Normalization used by BOTH the lookup key and the encrypted
     * display value — a bare trim only (unlike Gmail's mailbox
     * normalization, `item_id` is not human-typed and not case-variant
     * by any documented Plaid convention, so lower-casing it would risk
     * corrupting a value Plaid itself treats as case-sensitive opaque
     * data). Throws (route()'s own precondition — a write MUST have a
     * real item_id to route) rather than silently writing a
     * degenerate/empty-string route.
     */
    private function normalizeItemId(string $itemId): string
    {
        $normalized = trim($itemId);

        if ($normalized === '') {
            throw new InvalidArgumentException('PlaidItemRoutingService::route() requires a non-empty item_id.');
        }

        return $normalized;
    }

    private function hmacFor(string $normalizedItemId): string
    {
        return hash_hmac('sha256', $normalizedItemId, $this->hmacKey());
    }

    /**
     * Fail-closed: a missing/empty configured key is a configuration
     * defect, never silently substituted with a weaker/derived value
     * (e.g. APP_KEY) — mirrors this codebase's established discipline
     * for every other required, dedicated secret
     * (`GmailMailboxRoutingService::hmacKey()`'s identical fail-closed
     * treatment).
     */
    private function hmacKey(): string
    {
        $key = config('integrations.oauth_apps.plaid.item_routing_hmac_key');

        if (! is_string($key) || trim($key) === '') {
            throw new RuntimeException(
                'PlaidItemRoutingService requires integrations.oauth_apps.plaid.item_routing_hmac_key '.
                'to be configured with a dedicated, platform-wide secret — never derived from APP_KEY, never reused across purposes.'
            );
        }

        return $key;
    }
}
