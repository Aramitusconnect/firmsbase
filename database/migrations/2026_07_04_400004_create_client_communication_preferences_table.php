<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * client_communication_preferences — client_id is a deferred FK:
 * `clients` does not exist yet, so client_id is a plain nullable
 * unsigned bigint with no foreign key constraint. The client phase
 * adds the real constrained FK via ALTER TABLE once `clients` exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_communication_preferences', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->unsignedBigInteger('client_id')->nullable();

            $table->string('preferred_language', 10)->default('en');
            $table->string('preferred_timezone')->nullable();
            $table->string('notification_frequency')->nullable();
            $table->boolean('do_not_contact')->default(false);

            $table->timestamps();

            $table->index('firm_id');
            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_communication_preferences');
    }
};
