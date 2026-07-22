<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * integration_webhook_routing_index — Checkpoint 7 of the Stage B
 * Integration Platform mission ("Inbound Webhook Security";
 * reviews/checkpoint-07/frozen-design-post-security-review.md §5.1;
 * agent-7h-security-design-review.md §1.3). The ONE new access-control
 * mechanism this checkpoint introduces: a bounded, pre-tenant lookup
 * table that maps a hashed routing token (delivered on an inbound
 * webhook request header, never the URL) to the exact
 * {firm_id, firm_integration_id} pair it belongs to, with no secret
 * material of any kind and no RLS bypass required to read it.
 *
 * WHY THIS TABLE HAS NO RLS AND NO FORCE RLS (deliberate, not an
 * oversight — do not "fix" this later without re-reading this note;
 * mirrors integration_providers' own "WHY THIS TABLE HAS NO RLS"
 * docblock convention verbatim, see
 * database/migrations/2026_09_01_010001_create_integration_providers_table.php):
 *   - This table's entire purpose is to be readable BEFORE any tenant
 *     context exists at all — WebhookConnectionResolverService::
 *     resolveConnectionIdentity() (Step 1 of the frozen design's
 *     four-step identity-scoped secret-resolution mechanism, §5) must
 *     look up {firm_id, firm_integration_id} from an inbound request
 *     carrying nothing but a provider key and an opaque routing token,
 *     with app.current_firm_id not yet set. A FORCE RLS policy here
 *     would make that lookup structurally impossible without either a
 *     SECURITY DEFINER function (explicitly rejected — see the frozen
 *     design §5/§6/checklist item 8) or a session-GUC-gated carve-out
 *     policy (explicitly rejected as an undisclosed, unprecedented
 *     deviation from the frozen Checkpoint 0 spec text — see
 *     agent-7h-security-design-review.md §1.3's three-part reasoning).
 *   - Rows carry NO secret or credential material whatsoever — only a
 *     one-way sha256 hash of an opaque routing token (never the raw
 *     token itself), plus the two identifiers this lookup exists to
 *     resolve. There is nothing here a cross-tenant read could leak
 *     beyond "this hash belongs to this firm/connection", and
 *     `webhook_routing_token_hash` is unusable to derive a valid
 *     signature or reconstruct the raw token (one-way hash, and
 *     possession of the token alone never authorizes processing
 *     anyway — see the frozen design §4's "Authorization semantics"
 *     row: a valid HMAC signature is still separately required).
 *   - Every column is written ONLY by
 *     App\Integrations\Services\ProviderConnectionService::
 *     enableWebhookRouting()/disableWebhookRouting() and by
 *     disconnect()'s extended clearing step (all three in the SAME
 *     transaction that writes/clears firm_integrations.
 *     webhook_routing_token, so the plaintext-display column and this
 *     hashed-lookup row can never drift) — never by the webhook
 *     ingestion path itself, which only ever SELECTs this table.
 *   - This is structurally the ONLY table in this entire design that
 *     carries a firm-identifying pointer without RLS — a deliberate,
 *     reviewed, narrow exception, not a precedent for any other table.
 *     A future engineer must not "fix" this by adding RLS: doing so
 *     would break the pre-tenant bootstrap this table exists to serve,
 *     re-introducing exactly the SECURITY DEFINER/session-GUC problem
 *     this design was reviewed specifically to avoid.
 *
 * `firm_integration_id` — bare column; the composite FK below (mirrors
 * every prior checkpoint's firm_integrations(firm_id, id) composite-FK
 * precedent, e.g. integration_oauth_states' own create migration) is
 * the sole constraint governing it, so a row cannot reference a
 * firm_integration belonging to a different firm than `firm_id` claims.
 *
 * `webhook_routing_token_hash` — sha256 hex digest (64 hex chars),
 * GLOBALLY unique (not merely unique-per-firm): the entire point of
 * this table is that one hash resolves to exactly one connection,
 * system-wide, with no firm_id/provider disambiguation needed at
 * lookup time. This is also what makes
 * integration_webhook_receipts.routing_token_hash (§10.1 of the
 * frozen design) a safe, fully connection-scoped idempotency key
 * without that table ever carrying a firm-resolving column itself.
 *
 * Registry classification: `Global` in
 * RowLevelSecurityCoverageMappingService::FULL_TABLE_INVENTORY_EXTRA
 * (App\Services\RowLevelSecurityCoverageMappingService), with an
 * explicit disclaimer note — see that file's own updated entry for
 * this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_webhook_routing_index', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('firm_integration_id'); // bare column; composite FK below is the sole constraint
            $table->foreignId('integration_provider_id')->constrained('integration_providers')->restrictOnDelete();

            $table->string('webhook_routing_token_hash', 64);

            $table->timestamps();

            $table->unique('webhook_routing_token_hash');
            $table->index(['firm_id', 'firm_integration_id']);

            $table->foreign(['firm_id', 'firm_integration_id'])
                ->references(['firm_id', 'id'])->on('firm_integrations')
                ->cascadeOnDelete();

            // Deliberately NO $table->id()-style uuid, NO
            // enableRowLevelSecurity() call, and NO companion RLS
            // migration for this table — see this migration's class
            // docblock ("WHY THIS TABLE HAS NO RLS AND NO FORCE RLS")
            // for the full, required-reading reasoning.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_webhook_routing_index');
    }
};
