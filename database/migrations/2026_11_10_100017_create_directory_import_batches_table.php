<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * directory_import_batches — Mission 2 (MyAttorney Marketplace Core),
 * sections 53-55. A deliberately PARALLEL, purpose-built import batch
 * table — NOT a reuse of the existing FORCE-RLS, Firm-scoped
 * `import_batches` table the Mission 2 design doc originally proposed
 * reusing "as-is". That plan predates a real architectural
 * incompatibility discovered during checkpoint 9 implementation:
 * `ImportBatchService::create()` requires a real tenant `Firm`, and
 * `ImportApplyService`'s own docblock guarantees "every created
 * record's firm_id is always $batch->firm_id" — but a marketplace CSV
 * import is a platform-wide SuperAdmin action creating/updating
 * platform-GLOBAL `directory_firms` rows with no real tenant owner at
 * all (section 6/59's own "never blindly apply Firm tenant RLS to
 * global public directory records"). Stamping some arbitrary
 * importing-admin-adjacent Firm onto every imported listing would be
 * actively wrong, not a reuse shortcut. This table mirrors the
 * CONCEPTUAL shape of the generic import pipeline (stage -> validate
 * -> preview -> confirm -> apply, real batch history, real per-row
 * audit trail) without the tenant-scoping mismatch — Global/RLS-exempt,
 * same as every other Mission 2 marketplace table.
 *
 * `source_rights_confirmed` is the concrete implementation of section
 * 27: an import cannot reach Confirmed/Applied until the importing
 * admin explicitly attests to it — a real self-attestation step, never
 * inferred.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('directory_import_batches', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('created_by_platform_admin_id')->nullable()->constrained('platform_admins')->nullOnDelete();

            $table->string('original_filename');
            $table->string('status')->default('staged');
            $table->boolean('source_rights_confirmed')->default(false);

            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('invalid_rows')->default(0);
            $table->unsignedInteger('duplicate_rows')->default(0);
            $table->unsignedInteger('applied_rows')->default(0);
            $table->unsignedInteger('skipped_rows')->default(0);

            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('directory_import_batches');
    }
};
