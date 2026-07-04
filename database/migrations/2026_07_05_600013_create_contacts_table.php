<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * contacts — client_id is nullable: a contact can exist independent of
 * any client (e.g. a referral source or a matter-only contact).
 * normalized_search_keys backs conflict-check matching (name/email/
 * phone normalization). encrypted_sensitive_fields is reserved storage
 * for future field-level encryption via Phase 1's EncryptionKeyService
 * — Phase 2 does not wire that encryption yet (approved decision), the
 * column just exists so a later phase can populate it without another
 * schema change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();

            $table->string('name');
            $table->string('company')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('role')->nullable();

            $table->text('normalized_search_keys')->nullable();
            $table->text('encrypted_sensitive_fields')->nullable();

            $table->timestamps();

            $table->index('firm_id');
            $table->index('client_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
