<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * document_requests — the parent "please provide these documents"
 * record. status is an aggregate recomputed by DocumentRequestService
 * from its document_request_items, never hand-set independently.
 * Carries a public uuid per approved decision — the client portal's
 * mobile-safe upload flow needs to reference a specific request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('matter_id')->nullable()->constrained('matters')->nullOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();

            $table->string('status')->default('open');
            $table->string('title');
            $table->text('instructions')->nullable();
            $table->timestamp('due_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['firm_id', 'client_id']);
            $table->index('matter_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_requests');
    }
};
