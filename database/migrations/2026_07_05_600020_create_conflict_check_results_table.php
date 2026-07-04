<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * conflict_check_results — no firm_id column of its own; scoped
 * transitively through conflict_check_run_id ->
 * conflict_check_runs.firm_id, same reasoning as matter_parties.
 *
 * matched_type/matched_id form a lightweight polymorphic reference
 * (client|contact|party|matter_party|free_text) rather than several
 * separate nullable FK columns, since a single result row matches
 * exactly one of several possible source types. matched_id has no
 * database FK constraint (a true polymorphic reference cannot target
 * more than one table with a single FK) — matched_type disambiguates
 * it at the application layer. matched_value stores the actual
 * matched text (name/email/phone), which also covers the "free-text
 * opposing-party names" case: matched_type = free_text, matched_id =
 * null, matched_value = the free-text name itself.
 *
 * status defaults to possible_match, not clear — a result row is only
 * ever created when something matched; "clear" belongs to the run as
 * a whole having zero result rows, not to an individual result row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conflict_check_results', function (Blueprint $table) {
            $table->id();

            $table->foreignId('conflict_check_run_id')->constrained('conflict_check_runs')->cascadeOnDelete();

            $table->string('matched_type');
            $table->unsignedBigInteger('matched_id')->nullable();
            $table->string('matched_value');

            $table->string('status')->default('possible_match');

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();

            $table->timestamps();

            $table->index('conflict_check_run_id');
            $table->index(['matched_type', 'matched_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conflict_check_results');
    }
};
