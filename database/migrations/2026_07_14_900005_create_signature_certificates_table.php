<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * signature_certificates — signature_request_id is UNIQUE, enforcing a
 * true one-per-request guarantee at the database level (not just
 * service-layer convention) — the literal implementation of
 * "Completion certificate must be immutable after generation": there
 * is no code path that can ever create a second row for the same
 * request. certificate_data_json is immutable after insert (the
 * SignatureCertificate model blocks any update/delete). status has a
 * single value in this phase (Generated) — see SignatureCertificateStatus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signature_certificates', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('signature_request_id')->unique()->constrained('signature_requests')->restrictOnDelete();

            $table->string('status')->default('generated');
            $table->json('certificate_data_json');

            $table->foreignId('document_hash_id')->constrained('document_hashes')->restrictOnDelete();

            $table->timestamp('generated_at')->useCurrent();

            $table->index('firm_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signature_certificates');
    }
};
