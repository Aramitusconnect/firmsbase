<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * task_dependencies — fields match the master plan PDF's appendix row
 * exactly ("id; task_id; blocked_by_task_id; created_at" — no
 * updated_at). No own firm_id — scoped transitively through task_id.
 * A CHECK constraint blocks the trivial self-dependency case at the
 * database level; the general cycle-rejection rule (A depends on B
 * depends on A, or longer chains) is enforced by
 * TaskDependencyService at write time (project rule), not by the
 * database, since a general graph-cycle check is not expressible as a
 * single-row CHECK constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_dependencies', function (Blueprint $table) {
            $table->id();

            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('blocked_by_task_id')->constrained('tasks')->cascadeOnDelete();

            $table->timestamp('created_at')->useCurrent();

            $table->unique(['task_id', 'blocked_by_task_id']);
            $table->index('blocked_by_task_id');
        });

        DB::statement(
            'ALTER TABLE task_dependencies ADD CONSTRAINT task_dependencies_no_self_reference '.
            'CHECK (task_id <> blocked_by_task_id)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('task_dependencies');
    }
};
