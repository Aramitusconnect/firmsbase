<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * activation_checklist_items — no firm_id column of its own; scoped
 * transitively through activation_checklist_id. See the model's doc
 * comment for why this is not denormalized.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activation_checklist_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('activation_checklist_id')->constrained('activation_checklists')->cascadeOnDelete();

            $table->string('item_key');
            $table->string('label');
            $table->boolean('is_required')->default(true);
            $table->boolean('is_complete')->default(false);

            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();

            $table->timestamp('waived_at')->nullable();
            $table->foreignId('waived_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('waiver_reason')->nullable();

            $table->timestamps();

            $table->unique(['activation_checklist_id', 'item_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activation_checklist_items');
    }
};
