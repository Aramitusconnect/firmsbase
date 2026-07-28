<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * integration_gmail_mailbox_routes — FirmsVault Live Integrations,
 * Checkpoint 3 ("Add Google Workspace integration provider";
 * checkpoint3-combined-design.md §5; checkpoint3-design-sync-webhooks.md
 * §6.4). Gmail's Cloud Pub/Sub push-delivery model uses ONE shared
 * topic/authenticated-push-subscription for every connected firm
 * platform-wide (not a per-firm/per-connection subscription — Google's
 * own documented usage pattern), so an inbound Gmail webhook notification
 * carries only a mailbox address, never a routing token/clientState the
 * way Calendar/Drive's channel-notification model does. This table is
 * the dedicated bridge from "an unauthenticated mailbox correlator" to
 * "which firm/connection this belongs to" — a NEW, DEDICATED table,
 * deliberately NOT a second row/writer inserted into the existing
 * `integration_webhook_routing_index` (rejected per the human reviewer's
 * binding mandate on this checkpoint's design — see
 * checkpoint3-design-sync-webhooks.md §6.4.1 — which remains fully
 * unmodified by this checkpoint, its documented "three named writers,
 * one identifier per connection" invariant fully preserved).
 *
 * WHY THIS TABLE HAS NO RLS AND NO FORCE RLS (deliberate, not an
 * oversight — do not "fix" this later without re-reading this note; a
 * DIRECT structural sibling of, and modeled byte-for-byte on,
 * `integration_webhook_routing_index`'s own "WHY THIS TABLE HAS NO RLS
 * AND NO FORCE RLS" docblock — see
 * database/migrations/2026_09_06_060001_create_integration_webhook_routing_index_table.php
 * for the original of this reasoning, which this note cross-references
 * directly rather than restating from scratch):
 *   - This table's entire purpose is to be readable BEFORE any tenant
 *     context exists at all — GmailMailboxRoutingService::resolveByMailbox()
 *     (App\Integrations\Support\GmailMailboxRoutingService) must look up
 *     {firm_id, firm_integration_id} from an inbound Gmail Pub/Sub push
 *     request carrying nothing but a mailbox address, with
 *     app.current_firm_id not yet set. A FORCE RLS policy here would make
 *     that lookup structurally impossible without either a SECURITY
 *     DEFINER function or a session-GUC-gated carve-out policy — both
 *     explicitly rejected for the identical reasons
 *     integration_webhook_routing_index's own migration already
 *     documents (agent-7h-security-design-review.md §1.3's three-part
 *     reasoning), never re-litigated or re-opened here.
 *   - Rows carry NO secret or credential material whatsoever. Unlike the
 *     sibling table's own `webhook_routing_token_hash` (a plain SHA-256
 *     of a 256-bit CSPRNG value — safe as a plain hash because the input
 *     space is astronomically large and unguessable), this table's
 *     `mailbox_lookup_hmac` is a KEYED HMAC-SHA256, deliberately NOT a
 *     plain hash: a Gmail mailbox address is a small, structured, often-
 *     guessable string (a firm's own known domain, common local parts),
 *     so a plain SHA-256 of a normalized email would be trivially
 *     dictionary-attackable offline by anyone who obtained a copy of
 *     this table. The HMAC key is a NEW, DEDICATED, platform-wide secret
 *     (`config('integrations.oauth_apps.googleworkspace.gmail_mailbox_routing_hmac_key')`,
 *     generated once via `random_bytes(32)` — the same CSPRNG discipline
 *     `ProviderConnectionService::generateRawWebhookRoutingToken()`
 *     already uses), never derived from `APP_KEY` and never a per-firm
 *     `EmailBodyEncryptionService` key (wrong shape for a lookup that
 *     must resolve identically regardless of which firm owns the row —
 *     see checkpoint3-design-sync-webhooks.md §6.4.4). Without the key,
 *     this column cannot be dictionary-attacked offline. The display
 *     value (`mailbox_display_ciphertext`) is likewise never stored in
 *     plaintext — it is per-firm `EmailBodyEncryptionService` ciphertext,
 *     the SAME service/pattern `integration_sync_cursors.cursor_value`
 *     already uses. There is nothing here a cross-tenant read could leak
 *     beyond "this HMAC digest belongs to this firm/connection" —
 *     structurally identical in risk shape to the sibling table's own
 *     hash-only rows, not a new class of exposure.
 *   - Every column is written ONLY by
 *     App\Integrations\Support\GmailMailboxRoutingService::route()
 *     (the sole writer — deletes any existing row for the connection
 *     before inserting the new one, mirroring
 *     `enableWebhookRouting()`'s own "delete before insert, never
 *     `updateOrCreate()`" discipline) and ::unroute() (the sole
 *     deleter) — never by the webhook ingestion path itself, which
 *     only ever SELECTs this table via ::resolveByMailbox().
 *   - This is structurally the SECOND table in this mission's entire
 *     design that carries a firm-identifying pointer without RLS — a
 *     deliberate, reviewed, narrow exception mirroring
 *     `integration_webhook_routing_index`'s own precedent exactly, not
 *     a general pattern for any other table. A future engineer must not
 *     "fix" this by adding RLS: doing so would break the pre-tenant
 *     Gmail webhook routing bootstrap this table exists to serve,
 *     re-introducing exactly the SECURITY DEFINER/session-GUC problem
 *     both this table's and its sibling's design were reviewed
 *     specifically to avoid.
 *
 * `firm_integration_id` — bare column; the composite FK below (mirrors
 * every prior checkpoint's firm_integrations(firm_id, id) composite-FK
 * precedent, e.g. integration_webhook_routing_index's own create
 * migration) is the sole constraint governing it.
 *
 * `mailbox_lookup_hmac` — HMAC-SHA256 hex digest (64 hex chars),
 * GLOBALLY unique (not merely unique-per-firm): Gmail's shared Pub/Sub
 * topic means a mailbox correlator must resolve to AT MOST ONE active
 * connection platform-wide, exactly the same structural reason the
 * sibling table's hash column is globally, not per-firm, unique. This is
 * the DB-level enforcement of "one active Gmail mailbox cannot route
 * ambiguously to multiple active connections" — a second, different
 * connection attempting to route the same mailbox fails at the DB layer
 * with a real, catchable exception, never a silent overwrite.
 *
 * No `status` column and no soft-disable state, matching
 * `integration_webhook_routing_index`'s own existing "delete, don't
 * soft-disable" discipline: rows exist only while the owning
 * connection's Gmail webhook routing is active.
 *
 * Registry classification: `Global` in
 * RowLevelSecurityCoverageMappingService::FULL_TABLE_INVENTORY_EXTRA
 * (App\Services\RowLevelSecurityCoverageMappingService), registered in
 * the SAME change that creates this table (checkpoint3-security-review.md
 * Finding 3, required) — see that file's EXEMPT_TABLES/
 * EXEMPT_TABLE_METADATA/FULL_TABLE_INVENTORY_EXTRA entries for this
 * table, mirroring integration_webhook_routing_index's own three entries
 * exactly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_gmail_mailbox_routes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('firm_integration_id'); // bare column; composite FK below is the sole constraint
            $table->foreignId('integration_provider_id')->constrained('integration_providers')->restrictOnDelete();

            // Keyed HMAC-SHA256 (hex, 64 chars) of the normalized
            // (trimmed, lower-cased) Gmail mailbox address, keyed with a
            // dedicated, platform-wide secret distinct from both per-firm
            // encryption keys (EmailBodyEncryptionService is keyed
            // per-firm -- wrong shape for a GLOBAL, pre-tenant-context
            // lookup, see this migration's class docblock) and APP_KEY
            // (never reused across purposes). NEVER a plain sha256() of
            // the mailbox -- see this migration's class docblock.
            $table->string('mailbox_lookup_hmac', 64);

            // The encrypted display value -- per-firm
            // EmailBodyEncryptionService ciphertext, the SAME
            // service/pattern integration_sync_cursors.cursor_value
            // already uses. Never the plaintext mailbox address at rest.
            $table->text('mailbox_display_ciphertext');
            $table->foreignId('mailbox_display_encryption_key_id')->constrained('tenant_encryption_keys')->restrictOnDelete();

            $table->timestamps();

            // Global uniqueness (not per-firm) -- deliberately mirrors
            // integration_webhook_routing_index.webhook_routing_token_hash's
            // own global-uniqueness discipline: Gmail's shared Pub/Sub
            // topic means a mailbox correlator must resolve to AT MOST
            // ONE active connection platform-wide, exactly the same
            // structural reason that table's hash column is globally,
            // not per-firm, unique.
            $table->unique('mailbox_lookup_hmac');
            $table->index(['firm_id', 'firm_integration_id']);

            $table->foreign(['firm_id', 'firm_integration_id'])
                ->references(['firm_id', 'id'])->on('firm_integrations')
                ->cascadeOnDelete();

            // Deliberately NO enableRowLevelSecurity() call and NO
            // companion RLS migration for this table -- see this
            // migration's class docblock ("WHY THIS TABLE HAS NO RLS AND
            // NO FORCE RLS") for the full, required-reading reasoning.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_gmail_mailbox_routes');
    }
};
