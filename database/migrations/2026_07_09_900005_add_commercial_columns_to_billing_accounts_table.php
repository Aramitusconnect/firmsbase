<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive-only: adds organization_id/bill_to_contact/payment_method_ref
 * to the EXISTING billing_accounts table (created in Phase 1). Does not
 * recreate billing_accounts. consolidation_mode is deliberately NOT
 * duplicated here — it already exists on organizations (Phase 1); the
 * master plan's representative field catalog listing it under
 * billing_accounts too is treated as catalog imprecision, not a
 * literal second column (flagged and accepted in the approved
 * manifest).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('billing_accounts', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('name')
                ->constrained('organizations')->nullOnDelete();
            $table->string('bill_to_contact')->nullable()->after('billing_email');
            $table->string('payment_method_ref')->nullable()->after('bill_to_contact');
        });
    }

    public function down(): void
    {
        Schema::table('billing_accounts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organization_id');
            $table->dropColumn(['bill_to_contact', 'payment_method_ref']);
        });
    }
};
