<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Completes the deferred FK Phase 1 deliberately left unconstrained:
 * client_communication_preferences.client_id was a plain nullable
 * unsigned bigint because `clients` did not exist yet. It exists now
 * (this migration set). No data backfill is needed — the column has
 * always been null in every environment, since no client feature
 * existed before Phase 2. Expand/contract discipline: this only adds
 * a constraint, it does not change the column's type or drop anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_communication_preferences', function (Blueprint $table) {
            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('client_communication_preferences', function (Blueprint $table) {
            $table->dropForeign(['client_id']);
        });
    }
};
