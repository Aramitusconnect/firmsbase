<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * provider_balance_snapshots — a small, durable, display-only snapshot
 * of the LAST LIVE Balance retrieval per (firm_integration_id, account)
 * (checkpoint4-design-cost-control.md §5.3; checkpoint4-combined-design.md
 * §1.9). Upserted only on a `finalized_billable` Balance outcome
 * (pipeline step 15). Deliberately NOT the pipeline's step-8 response
 * cache (which never applies to Balance at all — Balance is real-time-
 * only per Plaid's own documentation) — this is what "cached balance
 * age" means in the product owner's own safeguard requirement: the age
 * of the last real retrieval, never a TTL-cached substitute for a new
 * one.
 *
 * Column list: the four scalar fields §5.3's prose names
 * (`available_cents`/`current_cents`/`iso_currency_code`/`retrieved_at`)
 * — the same scalar-only, never-raw-response discipline
 * `SanitizedUsageMetadataReference` already enforces elsewhere in this
 * domain — filled out to the standard `id`/`uuid`/`firm_id`/
 * `firm_integration_id`/timestamps shape every sibling table in this
 * document uses, per §1.9's own explicit note that the schema itself
 * ("beyond what's already named in prose") was left for implementation
 * time.
 *
 * Direct `BelongsToTenant` + FORCE RLS (companion migration) — resolved
 * with confidence in the combined design (§1.9): every row is
 * unambiguously firm-owned, no platform-default/global-row shape is
 * ever contemplated for this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_balance_snapshots', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->unsignedBigInteger('firm_integration_id');

            $table->string('account_id');

            $table->unsignedInteger('available_cents')->nullable();
            $table->unsignedInteger('current_cents')->nullable();
            $table->string('iso_currency_code', 3)->nullable();
            $table->timestamp('retrieved_at');

            $table->timestamps();

            $table->unique(['firm_integration_id', 'account_id']);
            $table->index(['firm_id', 'firm_integration_id']);

            $table->foreign(['firm_id', 'firm_integration_id'], 'provider_balance_snapshots_firm_integration_fk')
                ->references(['firm_id', 'id'])->on('firm_integrations')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_balance_snapshots');
    }
};
