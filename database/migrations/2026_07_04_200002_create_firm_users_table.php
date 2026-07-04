<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firm_users', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();

            $table->string('role');
            $table->string('status')->default('invited');
            $table->boolean('is_primary')->default(false);

            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('invitation_token')->nullable()->unique();
            $table->timestamp('invitation_accepted_at')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'firm_id']);
            $table->index('firm_id');
            $table->index('role');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('firm_users');
    }
};
