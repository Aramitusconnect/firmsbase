<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * document_versions — a detail record of its parent document, no own
 * firm_id (tenant isolation flows through document_id). Exactly one
 * version per document may be status=Current — enforced by
 * DocumentReplacementService, never by a database constraint alone
 * (a partial unique index would work but adds little here since this
 * table is never queried outside its parent's context).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_versions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('document_id')->constrained('documents')->cascadeOnDelete();

            $table->unsignedInteger('version_number');
            $table->string('status')->default('current');

            $table->string('storage_disk');
            $table->string('storage_path');
            $table->string('file_hash');
            $table->unsignedBigInteger('size_bytes');

            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['document_id', 'version_number']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_versions');
    }
};
