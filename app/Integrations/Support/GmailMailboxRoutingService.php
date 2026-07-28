<?php

declare(strict_types=1);

namespace App\Integrations\Support;

use App\Integrations\Data\ResolvedGmailMailboxRoute;
use App\Integrations\Models\FirmIntegration;
use App\Services\EmailBodyEncryptionService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * GmailMailboxRoutingService — Checkpoint 3 (Google Workspace provider),
 * checkpoint3-combined-design.md §5/§6.4;
 * checkpoint3-design-sync-webhooks.md §6.4 (the human-reviewer- and
 * security-review-corrected design). The SOLE writer/reader of the new,
 * dedicated `integration_gmail_mailbox_routes` table — a closed writer
 * discipline mirroring `integration_webhook_routing_index`'s own
 * documented "named writers only" convention exactly (see that table's
 * migration docblock). No other class may write or delete rows in this
 * table.
 *
 * WHY THIS TABLE/SERVICE EXISTS AT ALL, RATHER THAN REUSING
 * `integration_webhook_routing_index`: Gmail's Cloud Pub/Sub push model
 * has no per-connection token/clientState-equivalent field to round-trip
 * (unlike Calendar/Drive's `X-Goog-Channel-Token`, which reuses that
 * existing table unmodified — see GoogleWorkspaceProvider's own
 * verifyInboundSignature()/subscribe() docblocks). The routing lookup
 * this service performs (an inbound Gmail Pub/Sub delivery's `emailAddress`
 * field -> the owning {firm_id, firm_integration_id}) is therefore a
 * genuinely new, Gmail-specific need — deliberately met by a new,
 * narrowly-scoped table rather than an undocumented second row/writer
 * inserted into the frozen, security-reviewed `integration_webhook_routing_index`,
 * per the human reviewer's binding mandate (checkpoint3-design-sync-webhooks.md
 * §6.4.1).
 *
 * WHY A KEYED HMAC, NOT A PLAIN HASH: `integration_webhook_routing_index.webhook_routing_token_hash`
 * and `integration_oauth_states.opaque_token_hash` are both plain
 * sha256() of a 256-bit CSPRNG value — safe as a plain hash because the
 * input space is astronomically large and unguessable. A Gmail mailbox
 * address is the opposite: a small, structured, often-guessable string
 * (a firm's own known domain, common local parts) — a plain sha256() of
 * a normalized email would be trivially dictionary-attackable offline by
 * anyone who obtains a copy of this table. `mailbox_lookup_hmac` is
 * therefore a KEYED HMAC-SHA256, keyed by a new, dedicated, platform-wide
 * secret (`config('integrations.oauth_apps.googleworkspace.gmail_mailbox_routing_hmac_key')`,
 * generated once via `random_bytes(32)` at provisioning time — never
 * derived from `APP_KEY`, never reused across purposes, and NEVER
 * `App\Services\EmailBodyEncryptionService`'s own per-firm keys, which
 * are the wrong shape for a lookup that must succeed BEFORE any firm
 * context exists on the inbound request).
 *
 * WHY NO RLS ON THE UNDERLYING TABLE (mirrors `integration_webhook_routing_index`'s
 * own "WHY THIS TABLE HAS NO RLS" docblock convention — see this
 * service's owning migration for the authoritative, full-length version
 * of this reasoning): `resolveByMailbox()` below is the one
 * pre-tenant-context read this whole feature depends on — an inbound
 * Gmail Pub/Sub delivery carries no firm identity at all until this
 * lookup resolves one. A FORCE RLS policy keyed on
 * `app.current_firm_id` would make that lookup structurally impossible.
 * This table therefore carries no RLS and no FORCE ROW LEVEL SECURITY,
 * a deliberate, disclosed, coordinator-reviewed exception
 * (checkpoint3-combined-design.md §5/§9 item 1) — never something a
 * future engineer should "fix" by adding a policy here.
 *
 * Every write below is deliberately NOT wrapped in this class's own
 * `DB::transaction()` — `route()`'s delete-then-insert pair and
 * `unroute()`'s single delete are both meant to be called from INSIDE
 * the caller's own already-open transaction (GoogleWorkspaceProvider::
 * subscribe()/renewSubscription(), themselves reached from
 * ProviderConnectionService's own connect/disconnect/renewal
 * transaction — checkpoint3-combined-design.md §4.7), exactly mirroring
 * `App\Integrations\Services\SyncCursorService::advance()`'s identical,
 * already-established "this method does not open one of its own"
 * discipline.
 */
final class GmailMailboxRoutingService
{
    public function __construct(private readonly EmailBodyEncryptionService $encryption) {}

    /**
     * The SOLE writer. Deletes any existing row for
     * `$connection->id` first (mirrors
     * `ProviderConnectionService::enableWebhookRouting()`'s own
     * "delete before insert, never `updateOrCreate()`" discipline, so a
     * connection can never accumulate more than one resolvable mailbox
     * mapping), then inserts the new row: a keyed HMAC lookup value plus
     * an encrypted display value (via the SAME
     * `EmailBodyEncryptionService` pattern
     * `App\Integrations\Services\SyncCursorService` already uses for
     * `cursor_value` — never a second encryption system).
     *
     * The `mailbox_lookup_hmac` UNIQUE constraint means a second,
     * DIFFERENT connection attempting to route the SAME mailbox fails
     * here at the DB layer — a real, catchable
     * `Illuminate\Database\QueryException`, deliberately allowed to
     * propagate UNCAUGHT (never a silent overwrite of the first
     * connection's route) so the caller's own ambient transaction rolls
     * back the whole operation rather than degrading silently
     * (checkpoint3-combined-design.md §4.7's "Byproduct" — an
     * ambiguous-mailbox `route()` failure rolls back the entire OAuth
     * connect, never leaving a connection `Active` with webhooks
     * silently broken).
     *
     * $mailboxEmail MUST be sourced from an authenticated, first-party
     * Google API response (Gmail's own `users.getProfile` —
     * see GoogleWorkspaceProvider::subscribe()/renewSubscription()) —
     * NEVER the unverified inbound webhook `emailAddress` field. This
     * method itself does not and cannot enforce that provenance; it is
     * the caller's responsibility, exactly as the combined design's own
     * lifecycle table requires.
     */
    public function route(FirmIntegration $connection, string $mailboxEmail): void
    {
        $normalized = $this->normalizeMailbox($mailboxEmail);
        $hmac = $this->hmacFor($normalized);

        $result = $this->encryption->encrypt($connection->firm, $normalized);

        if (! $result->succeeded) {
            throw new RuntimeException(
                "GmailMailboxRoutingService::route() could not encrypt the mailbox display value for connection {$connection->id}: {$result->reason}"
            );
        }

        DB::table('integration_gmail_mailbox_routes')
            ->where('firm_integration_id', $connection->id)
            ->delete();

        DB::table('integration_gmail_mailbox_routes')->insert([
            'firm_id' => $connection->firm_id,
            'firm_integration_id' => $connection->id,
            'integration_provider_id' => $connection->integration_provider_id,
            'mailbox_lookup_hmac' => $hmac,
            'mailbox_display_ciphertext' => $result->ciphertext,
            'mailbox_display_encryption_key_id' => $result->encryptionKeyId,
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
        DB::table('integration_gmail_mailbox_routes')
            ->where('firm_integration_id', $connection->id)
            ->delete();
    }

    /**
     * The SOLE reader — the pre-tenant-context lookup this whole
     * feature exists to serve. Anti-enumeration / collapse-to-null
     * discipline, matching every other pre-tenant-context resolver in
     * this codebase (`App\Integrations\Services\WebhookConnectionResolverService::resolveConnectionIdentity()`'s
     * identical shape): returns null for EVERY non-usable case (empty
     * mailbox, unknown mailbox) — NEVER throws, so a caller can never
     * distinguish "malformed input" from "no such route" by exception
     * type alone.
     */
    public function resolveByMailbox(string $mailboxEmail): ?ResolvedGmailMailboxRoute
    {
        $normalized = trim(strtolower($mailboxEmail));

        if ($normalized === '') {
            return null;
        }

        $hmac = $this->hmacFor($normalized);

        $row = DB::table('integration_gmail_mailbox_routes')
            ->where('mailbox_lookup_hmac', $hmac)
            ->first();

        if ($row === null) {
            return null;
        }

        return new ResolvedGmailMailboxRoute(
            firmId: (int) $row->firm_id,
            firmIntegrationId: (int) $row->firm_integration_id,
            integrationProviderId: (int) $row->integration_provider_id,
        );
    }

    /**
     * Normalization used by BOTH the lookup key and the encrypted
     * display value — trim + lowercase, so a case-variant or
     * whitespace-padded mailbox string always resolves to the identical
     * row. Throws (route()'s own precondition — a write MUST have a
     * real mailbox to route) rather than silently writing a
     * degenerate/empty-string route.
     */
    private function normalizeMailbox(string $mailboxEmail): string
    {
        $normalized = trim(strtolower($mailboxEmail));

        if ($normalized === '') {
            throw new InvalidArgumentException('GmailMailboxRoutingService::route() requires a non-empty mailbox address.');
        }

        return $normalized;
    }

    private function hmacFor(string $normalizedMailbox): string
    {
        return hash_hmac('sha256', $normalizedMailbox, $this->hmacKey());
    }

    /**
     * Fail-closed: a missing/empty configured key is a configuration
     * defect, never silently substituted with a weaker/derived value
     * (e.g. APP_KEY) — mirrors this codebase's established discipline
     * for every other required, dedicated secret
     * (`App\Integrations\Support\ProviderEnvironmentResolver`'s own
     * fail-closed treatment of missing config).
     */
    private function hmacKey(): string
    {
        $key = config('integrations.oauth_apps.googleworkspace.gmail_mailbox_routing_hmac_key');

        if (! is_string($key) || trim($key) === '') {
            throw new RuntimeException(
                'GmailMailboxRoutingService requires integrations.oauth_apps.googleworkspace.gmail_mailbox_routing_hmac_key '.
                'to be configured with a dedicated, platform-wide secret — never derived from APP_KEY, never reused across purposes.'
            );
        }

        return $key;
    }
}
