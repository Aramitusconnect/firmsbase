<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * document_chase_events — append-only. event_type is a plain string
 * (approved clarification — future-extensible event log, same
 * treatment as timeline_events/payment_plan_events/
 * payment_classification_events).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_chase_events', function (Blueprint $table) {
            $table->id();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('document_request_item_id')->constrained('document_request_items')->cascadeOnDelete();
            $table->foreignId('document_chase_rule_id')->nullable()->constrained('document_chase_rules')->nullOnDelete();

            $table->string('event_type');
            $table->json('metadata_json')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['firm_id', 'document_request_item_id']);
            $table->index('event_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_chase_events');
    }
};
