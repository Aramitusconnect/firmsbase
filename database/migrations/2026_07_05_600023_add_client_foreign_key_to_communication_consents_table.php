<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Completes the deferred FK Phase 1 deliberately left unconstrained on
 * communication_consents.client_id — same reasoning as the sibling
 * migration for client_communication_preferences.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communication_consents', function (Blueprint $table) {
            $table->foreign('client_id')->references('id')->on('clients')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('communication_consents', function (Blueprint $table) {
            $table->dropForeign(['client_id']);
        });
    }
};
