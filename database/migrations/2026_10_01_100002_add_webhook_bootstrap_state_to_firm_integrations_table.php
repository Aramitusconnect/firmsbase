<?php

declare(strict_types=1);

use App\Integrations\Enums\WebhookBootstrapState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the webhook-bootstrap lifecycle to `firm_integrations` —
 * Checkpoint 8.2 §A7b.
 *
 * WHY. The webhook-subscription bootstrap used to run inside the OAuth
 * completion transaction, so a transient `subscribe()` failure rolled
 * back a completed, valid authorization (the exchanged credential
 * included) and the user had to start the whole connect flow again. It
 * also made a provider HTTP call while that transaction held `FOR UPDATE`
 * on this very row — the shape Checkpoint 8.1 proved deadlocks durable
 * cross-session writes.
 *
 * The bootstrap now happens AFTER the OAuth transaction commits, which
 * means the connection can be Active while its subscriptions are not yet
 * in place. These columns make that intermediate reality explicit and
 * durable instead of invisible. See
 * `App\Integrations\Enums\WebhookBootstrapState` for why this is tracked
 * separately from `status` rather than as a new `ConnectionStatus` case.
 *
 * EXPAND-ONLY. Three additive columns, all with a safe default or
 * nullable; no existing column is altered, renamed or dropped, and every
 * pre-existing row gets `not_required` — which is the correct reading of
 * history for any connection that has already finished connecting (its
 * subscriptions, if any, were bootstrapped inside the old transaction).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('firm_integrations', function (Blueprint $table) {
            $table->string('webhook_bootstrap_state')
                ->default(WebhookBootstrapState::NotRequired->value)
                ->after('last_health_status');

            // The sanitized failure category only — never a provider
            // message, never a payload (§A8).
            $table->string('webhook_bootstrap_error')->nullable()->after('webhook_bootstrap_state');

            $table->timestamp('webhook_bootstrap_attempted_at')->nullable()->after('webhook_bootstrap_error');
        });
    }

    public function down(): void
    {
        Schema::table('firm_integrations', function (Blueprint $table) {
            $table->dropColumn([
                'webhook_bootstrap_state',
                'webhook_bootstrap_error',
                'webhook_bootstrap_attempted_at',
            ]);
        });
    }
};
