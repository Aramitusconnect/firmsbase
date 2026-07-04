<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trial_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('opportunity_id')->constrained('opportunities')->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained('organizations')->nullOnDelete();

            $table->string('status')->default('requested');
            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('converted_at')->nullable();

            $table->timestamps();

            $table->index('opportunity_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trial_requests');
    }
};
