<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * expense_receipts — private by default, never a public URL, mirroring
 * Document/DocumentSecurityService's exact convention (file_hash is
 * caller-supplied, not computed internally — no real file-storage
 * pipeline exists yet anywhere in this codebase, same honesty finding
 * as Phase 11's DocumentHashService). Unique on expense_id: one receipt
 * per expense (Expense::receipt() is a hasOne), matching the approved
 * manifest's relationship design.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_receipts', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('expense_id')->unique()->constrained('expenses')->cascadeOnDelete();

            $table->string('storage_disk');
            $table->string('storage_path');
            $table->string('original_filename');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->string('file_hash');
            $table->foreignId('encryption_key_id')->nullable()->constrained('tenant_encryption_keys')->nullOnDelete();

            $table->foreignId('uploaded_by_firm_user_id')->nullable()->constrained('firm_users')->nullOnDelete();

            $table->timestamps();

            $table->index('firm_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_receipts');
    }
};
