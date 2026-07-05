<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * webhook_delivery_attempts — append-only (correction #13), mirrors
 * trust_ledger_entries' strict-immutability shape exactly: no uuid, no
 * status column beyond `outcome` (set once at creation, never
 * transitioned), no updated_at. webhook_secret_id (correction #7)
 * records which ACTIVE secret signed this specific attempt — because
 * secrets are rotatable, an old attempt must remain explainable even
 * after the subscription's active secret has since rotated. Never
 * stores the raw secret itself, only the FK reference.
 * response_snippet is length-capped at the application layer and must
 * never contain the raw secret, ciphertext, or any signature material.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhook_delivery_attempts', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('webhook_delivery_id')->constrained('webhook_deliveries')->cascadeOnDelete();
            $table->foreignId('webhook_secret_id')->nullable()->constrained('webhook_secrets')->nullOnDelete();

            $table->unsignedInteger('attempt_number');
            $table->string('outcome');
            $table->unsignedSmallInteger('http_status_code')->nullable();
            $table->string('response_snippet', 500)->nullable();
            $table->timestamp('attempted_at');

            $table->timestamp('created_at')->useCurrent();

            $table->index('webhook_delivery_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_delivery_attempts');
    }
};
