<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * accounting_export_batches — the firm-owned root of one fake/simulated
 * one-way QuickBooks Online export run. export_target is a closed enum
 * (AccountingExportTarget::QuickbooksOnline only) — no other target
 * exists. Blocked status is used when the batch is refused before a
 * single line is built (e.g. expenses entitlement disabled).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_export_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();

            $table->string('export_target')->default('quickbooks_online');
            $table->string('status')->default('requested');

            $table->foreignId('requested_by_firm_user_id')->constrained('firm_users')->cascadeOnDelete();

            $table->date('date_range_start');
            $table->date('date_range_end');

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('failed_reason')->nullable();

            $table->timestamps();

            $table->index('firm_id');
            $table->index(['firm_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_export_batches');
    }
};
