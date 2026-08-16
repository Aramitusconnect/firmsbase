<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * payment_intents — FirmsVault Pay Gate A2 (v1.4 §16/§17).
 *
 * WHY EXISTING SCHEMA IS INSUFFICIENT. `payments` is the canonical
 * record of money that HAS been received; it has no freeze semantics,
 * no supersede lineage, no destination-class split, and its own
 * docblock defines it as a payment, not an instruction. Overloading it
 * with pre-execution instruction state would corrupt the meaning of a
 * table the whole Billing domain already depends on. A PaymentIntent is
 * a distinct thing: a frozen, immutable instruction that may or may not
 * ever be executed.
 *
 * ARCHITECTURE ROLE. v3.1 `PaymentIntent`.
 *
 * TENANT OWNERSHIP. Tenant-owned (`firm_id`). RLS + FORCE RLS in the
 * companion migration.
 *
 * FOREIGN KEY STRATEGY. `client_id`/`matter_id`/`invoice_id` are plain
 * FKs to legacy tables, matching those tables' existing convention;
 * v1.4 §55 forbids broadly refactoring the legacy accounting domain for
 * this POC. The tenant-consistency guarantee for the NEW path is
 * carried by `UNIQUE (id, firm_id)`, which every new child table
 * composite-FKs against, so no new cross-firm reference is introduced
 * among Gate A2 tables (v1.4 §35/§36).
 *
 * MONEY. `amount_cents` is BIGINT with `CHECK (> 0)`. This deliberately
 * diverges from `payments.amount_cents`, which is an unsigned INTEGER
 * (a ~$21.4M ceiling); a new financial table should not inherit that
 * ceiling. `currency` is CHAR-checked to 'USD' — POC #1 is USD only
 * (v1.4 §44). Note the deliberate case divergence from
 * `payments.currency`, which defaults to lowercase 'usd': the new path
 * standardizes on the ISO-4217 uppercase form and never mixes them.
 *
 * ROLLBACK. Dropping this table drops the instruction history. Safe
 * only while no execution has occurred, which is the POC #1 state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_intents', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();

            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('matter_id')->nullable()->constrained('matters')->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();

            $table->bigInteger('amount_cents');
            $table->string('currency', 3)->default('USD');

            $table->string('purpose');
            $table->string('status')->default('draft');

            // Freeze/supersede lineage (§17). History is never
            // rewritten: a superseded intent keeps its original
            // material values and points forward.
            $table->timestamp('frozen_at')->nullable();
            $table->unsignedBigInteger('supersedes_payment_intent_id')->nullable();
            $table->unsignedBigInteger('superseded_by_payment_intent_id')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();

            // The hash of every material field, computed at freeze time.
            // Any later divergence is detectable even if a row were
            // mutated outside the model guard.
            $table->string('material_fingerprint', 64)->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['firm_id', 'status']);
            $table->index(['firm_id', 'invoice_id']);
            $table->index('supersedes_payment_intent_id');
            $table->index('superseded_by_payment_intent_id');

            // Composite-FK target for every Gate A2 child table.
            $table->unique(['id', 'firm_id'], 'payment_intents_id_firm_id_unique');
        });

        // Supersede lineage must stay inside one firm.
        DB::statement(<<<'SQL'
            ALTER TABLE payment_intents
            ADD CONSTRAINT payment_intents_supersedes_same_firm_fk
            FOREIGN KEY (supersedes_payment_intent_id, firm_id)
            REFERENCES payment_intents (id, firm_id)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE payment_intents
            ADD CONSTRAINT payment_intents_superseded_by_same_firm_fk
            FOREIGN KEY (superseded_by_payment_intent_id, firm_id)
            REFERENCES payment_intents (id, firm_id)
        SQL);

        DB::statement('ALTER TABLE payment_intents ADD CONSTRAINT payment_intents_amount_positive CHECK (amount_cents > 0)');

        DB::statement("ALTER TABLE payment_intents ADD CONSTRAINT payment_intents_usd_only CHECK (currency = 'USD')");

        DB::statement(<<<'SQL'
            ALTER TABLE payment_intents
            ADD CONSTRAINT payment_intents_status_values CHECK (
                status IN ('draft', 'frozen', 'superseded', 'cancelled')
            )
        SQL);

        // A frozen/superseded intent must carry its freeze evidence;
        // a draft must not pretend to have any.
        DB::statement(<<<'SQL'
            ALTER TABLE payment_intents
            ADD CONSTRAINT payment_intents_freeze_consistency CHECK (
                (status = 'draft' AND frozen_at IS NULL AND material_fingerprint IS NULL)
                OR (status IN ('frozen', 'superseded') AND frozen_at IS NOT NULL AND material_fingerprint IS NOT NULL)
                OR (status = 'cancelled')
            )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE payment_intents
            ADD CONSTRAINT payment_intents_supersede_consistency CHECK (
                (status = 'superseded' AND superseded_by_payment_intent_id IS NOT NULL AND superseded_at IS NOT NULL)
                OR (status <> 'superseded' AND superseded_by_payment_intent_id IS NULL)
            )
        SQL);

        // An intent can never supersede itself.
        DB::statement(<<<'SQL'
            ALTER TABLE payment_intents
            ADD CONSTRAINT payment_intents_no_self_supersede CHECK (
                supersedes_payment_intent_id IS NULL OR supersedes_payment_intent_id <> id
            )
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_intents');
    }
};
