<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * document_hashes — immutable append-only evidentiary row. hash_value
 * is CALLER-SUPPLIED (mirrors documents.file_hash's existing
 * precedent, confirmed by inspecting DocumentSecurityService::upload()
 * — it never computes a hash from real bytes internally). No real file
 * storage/rendering pipeline exists anywhere in this codebase yet, so
 * this service does not fabricate a hash from nothing; it durably and
 * immutably records whatever hash value the (future, real) storage
 * layer supplies. Created before this migration in table-creation
 * order because signature_events.document_hash_id references it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_hashes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();

            $table->string('source_document_type');
            $table->foreignId('document_id')->nullable()->constrained('documents')->restrictOnDelete();
            $table->foreignId('generated_document_id')->nullable()->constrained('generated_documents')->restrictOnDelete();

            $table->string('algorithm');
            $table->string('hash_value');

            $table->foreignId('recorded_by_firm_user_id')->nullable()->constrained('firm_users')->nullOnDelete();
            $table->timestamp('recorded_at')->useCurrent();

            $table->index('firm_id');
            $table->index(['document_id']);
            $table->index(['generated_document_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_hashes');
    }
};
