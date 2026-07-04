<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * tenant_encryption_keys — per-firm envelope-encryption key material.
 * encrypted_key stores the firm's data key, itself encrypted at rest
 * via Laravel's Crypt facade (APP_KEY is the outer layer). A partial
 * unique index (raw SQL — Blueprint has no fluent partial-index method)
 * enforces "at most one active key per firm" at the database layer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_encryption_keys', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->unsignedInteger('key_version')->default(1);
            $table->string('status')->default('active');
            $table->text('encrypted_key');

            $table->timestamp('destroyed_at')->nullable();
            // No FK: key_destruction_requests does not exist yet.
            $table->unsignedBigInteger('destruction_request_id')->nullable();

            $table->timestamps();

            $table->unique(['firm_id', 'key_version']);
            $table->index(['firm_id', 'status']);
        });

        DB::statement(
            'CREATE UNIQUE INDEX tenant_encryption_keys_one_active_per_firm '
            .'ON tenant_encryption_keys (firm_id) WHERE status = \'active\''
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS tenant_encryption_keys_one_active_per_firm');
        Schema::dropIfExists('tenant_encryption_keys');
    }
};
