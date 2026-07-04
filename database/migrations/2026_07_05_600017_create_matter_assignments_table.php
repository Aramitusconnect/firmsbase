<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * matter_assignments — no firm_id column of its own; scoped
 * transitively through matter_id -> matters.firm_id, same reasoning as
 * matter_parties. `role` is a freeform string (not a rigid FK/enum) —
 * typically mirrors FirmUserRole values but is not constrained to them,
 * since a matter may need a role label a firm-wide role doesn't cover.
 * removed_at (rather than deleting the row) avoids a hard delete on
 * what is effectively a staffing history record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matter_assignments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('matter_id')->constrained('matters')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->string('role')->nullable();
            $table->boolean('is_lead')->default(false);

            $table->timestamp('assigned_at')->useCurrent();
            $table->timestamp('removed_at')->nullable();

            $table->timestamps();

            $table->unique(['matter_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matter_assignments');
    }
};
