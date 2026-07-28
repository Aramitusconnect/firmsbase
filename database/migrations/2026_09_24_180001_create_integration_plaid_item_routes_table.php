<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * integration_plaid_item_routes — FirmsVault Live Integrations,
 * Checkpoint 4 ("Plaid financial evidence add-on";
 * checkpoint4-combined-design.md §1.1.1, binding "Option B";
 * checkpoint4-design-plaid-provider-core.md §11.2;
 * checkpoint4-security-review.md Finding 7, confirmed safe/sufficient).
 * Plaid's `item_id` arrives in every webhook body's JSON payload, never
 * a header, never a `clientState`-equivalent field the way
 * Calendar/Drive's channel-notification model provides. This table is
 * the dedicated bridge from "an unauthenticated item_id correlator" to
 * "which firm/connection this belongs to" — a NEW, DEDICATED table,
 * deliberately NOT a second row/writer inserted into the existing
 * `integration_webhook_routing_index` (mirrors the binding decision
 * already made for Checkpoint 3's Gmail mailbox-address case — see
 * `database/migrations/2026_09_23_170001_create_integration_gmail_mailbox_routes_table.php`,
 * this table's own direct structural precedent, byte-for-byte, applied
 * here to `item_id` instead of a mailbox address).
 *
 * WHY THIS TABLE HAS NO RLS AND NO FORCE RLS (deliberate, not an
 * oversight — do not "fix" this later without re-reading this note; a
 * DIRECT structural sibling of, and modeled byte-for-byte on, BOTH
 * `integration_webhook_routing_index`'s own "WHY THIS TABLE HAS NO RLS
 * AND NO FORCE RLS" docblock
 * (database/migrations/2026_09_06_060001_create_integration_webhook_routing_index_table.php)
 * AND `integration_gmail_mailbox_routes`'s identical, already-reviewed
 * application of that same reasoning to a second provider-generated
 * correlator
 * (database/migrations/2026_09_23_170001_create_integration_gmail_mailbox_routes_table.php)
 * — this table cross-references both directly rather than restating
 * the reasoning from scratch a third time):
 *   - This table's entire purpose is to be readable BEFORE any tenant
 *     context exists at all — PlaidItemRoutingService::resolveByItemId()
 *     (App\Integrations\Support\PlaidItemRoutingService) must look up
 *     {firm_id, firm_integration_id} from an inbound Plaid webhook
 *     request carrying nothing but an item_id, with app.current_firm_id
 *     not yet set. A FORCE RLS policy here would make that lookup
 *     structurally impossible without either a SECURITY DEFINER
 *     function or a session-GUC-gated carve-out policy — both
 *     explicitly rejected for the identical reasons both sibling
 *     tables' own migrations already document, never re-litigated or
 *     re-opened here.
 *   - Rows carry NO secret or credential material whatsoever. `item_id`
 *     is not officially documented by Plaid as having a guaranteed
 *     entropy floor (disclosed only as "an identifier... value must be
 *     considered opaque") — treated with the same conservative caution
 *     the Gmail mailbox-address case required: `item_lookup_hmac` is a
 *     KEYED HMAC-SHA256, deliberately NOT a plain hash. The HMAC key is
 *     a NEW, DEDICATED, platform-wide secret
 *     (`config('integrations.oauth_apps.plaid.item_routing_hmac_key')`,
 *     generated once via `random_bytes(32)` — the same CSPRNG
 *     discipline `gmail_mailbox_routing_hmac_key` already uses), never
 *     derived from `APP_KEY` and never a per-firm
 *     `EmailBodyEncryptionService` key (wrong shape for a lookup that
 *     must resolve identically regardless of which firm owns the row).
 *     Without the key, this column cannot be dictionary-attacked
 *     offline. The display value (`item_display_ciphertext`) is
 *     likewise never stored in plaintext — it is per-firm
 *     `EmailBodyEncryptionService` ciphertext, the SAME service/pattern
 *     `integration_gmail_mailbox_routes.mailbox_display_ciphertext`/
 *     `integration_sync_cursors.cursor_value` already use. There is
 *     nothing here a cross-tenant read could leak beyond "this HMAC
 *     digest belongs to this firm/connection" — structurally identical
 *     in risk shape to both sibling tables' own hash-only rows, not a
 *     new class of exposure.
 *   - Every column is written ONLY by
 *     App\Integrations\Support\PlaidItemRoutingService::route() (the
 *     sole writer — deletes any existing row for the connection before
 *     inserting the new one, mirroring
 *     `GmailMailboxRoutingService::route()`'s own "delete before
 *     insert, never `updateOrCreate()`" discipline) and ::unroute()
 *     (the sole deleter) — never by the webhook ingestion path itself,
 *     which only ever SELECTs this table via ::resolveByItemId().
 *   - This is structurally the THIRD table in this mission's entire
 *     design that carries a firm-identifying pointer without RLS — a
 *     deliberate, reviewed, narrow exception mirroring both sibling
 *     tables' own precedent exactly, not a general pattern for any
 *     other table. A future engineer must not "fix" this by adding
 *     RLS: doing so would break the pre-tenant Plaid webhook routing
 *     bootstrap this table exists to serve, re-introducing exactly the
 *     SECURITY DEFINER/session-GUC problem all three tables' designs
 *     were reviewed specifically to avoid.
 *
 * `firm_integration_id` — bare column; the composite FK below (mirrors
 * every prior checkpoint's firm_integrations(firm_id, id) composite-FK
 * precedent) is the sole constraint governing it.
 *
 * `item_lookup_hmac` — HMAC-SHA256 hex digest (64 hex chars), GLOBALLY
 * unique (not merely unique-per-firm): a Plaid `item_id` must resolve
 * to AT MOST ONE active connection platform-wide, exactly the same
 * structural reason both sibling tables' own hash columns are globally,
 * not per-firm, unique. This is the DB-level enforcement of "one active
 * Plaid Item cannot route ambiguously to multiple active connections" —
 * a second, different connection attempting to route the same item_id
 * fails at the DB layer with a real, catchable exception, never a
 * silent overwrite.
 *
 * No `status` column and no soft-disable state, matching both sibling
 * tables' own existing "delete, don't soft-disable" discipline: rows
 * exist only while the owning connection's Plaid Item routing is
 * active.
 *
 * Registry classification: `Global` in
 * RowLevelSecurityCoverageMappingService::FULL_TABLE_INVENTORY_EXTRA
 * (App\Services\RowLevelSecurityCoverageMappingService), registered in
 * the SAME change that creates this table (mirroring the requirement
 * Checkpoint 3's own security review made binding for
 * `integration_gmail_mailbox_routes` — see that file's EXEMPT_TABLES/
 * EXEMPT_TABLE_METADATA/FULL_TABLE_INVENTORY_EXTRA entries for this
 * table, mirroring the sibling table's own three entries exactly).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_plaid_item_routes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('firm_integration_id'); // bare column; composite FK below is the sole constraint
            $table->foreignId('integration_provider_id')->constrained('integration_providers')->restrictOnDelete();

            // Keyed HMAC-SHA256 (hex, 64 chars) of the Plaid item_id,
            // keyed with a dedicated, platform-wide secret distinct from
            // both per-firm encryption keys (EmailBodyEncryptionService
            // is keyed per-firm -- wrong shape for a GLOBAL,
            // pre-tenant-context lookup, see this migration's class
            // docblock) and APP_KEY (never reused across purposes).
            // NEVER a plain sha256() of item_id -- see this migration's
            // class docblock.
            $table->string('item_lookup_hmac', 64);

            // The encrypted display value -- per-firm
            // EmailBodyEncryptionService ciphertext, the SAME
            // service/pattern integration_gmail_mailbox_routes.mailbox_display_ciphertext/
            // integration_sync_cursors.cursor_value already use. Never
            // the plaintext item_id at rest.
            $table->text('item_display_ciphertext');
            $table->foreignId('item_display_encryption_key_id')->constrained('tenant_encryption_keys')->restrictOnDelete();

            $table->timestamps();

            // Global uniqueness (not per-firm) -- deliberately mirrors
            // both sibling tables' own global-uniqueness discipline: a
            // Plaid item_id must resolve to AT MOST ONE active
            // connection platform-wide.
            $table->unique('item_lookup_hmac');
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
        Schema::dropIfExists('integration_plaid_item_routes');
    }
};
