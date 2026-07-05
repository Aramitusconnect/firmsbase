<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * api_key_scopes — grant rows over the fixed ApiKeyScopeCode enum, one
 * row per (api_key_id, scope_code). No uuid — mirrors Phase 7's
 * PlatformRole precedent (a grant row looked up only via its parent +
 * code, never addressed individually).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_key_scopes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('api_key_id')->constrained('api_keys')->cascadeOnDelete();
            $table->string('scope_code');
            $table->timestamp('granted_at')->useCurrent();

            $table->timestamps();

            $table->unique(['api_key_id', 'scope_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_key_scopes');
    }
};
