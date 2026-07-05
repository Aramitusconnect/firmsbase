<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * trust_ledgers — one client's IOLTA sub-ledger within a firm's pooled
 * trust_accounts row. Unique on (trust_account_id, client_id): a
 * client has at most one sub-ledger per account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trust_ledgers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();
            $table->foreignId('trust_account_id')->constrained('trust_accounts')->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();

            $table->string('status')->default('active');

            $table->timestamps();

            $table->unique(['trust_account_id', 'client_id']);
            $table->index(['firm_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trust_ledgers');
    }
};
