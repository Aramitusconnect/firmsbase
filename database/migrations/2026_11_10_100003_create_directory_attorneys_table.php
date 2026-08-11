<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * directory_attorneys — Mission 2 (MyAttorney Marketplace Core),
 * section 10. A public attorney identity, independent of `FirmUser`/
 * `User` — an attorney record may exist even when the lawyer has never
 * used FirmsBase and their firm is unclaimed (confirmed by repository
 * audit: no such public-identity concept exists anywhere today; the
 * only prior "attorney" concept is FirmUserRole::Attorney, an internal
 * tenant-user role label, never a standalone identity). Global
 * platform data, same RLS-exemption reasoning as directory_firms.
 *
 * No verification-state column here by design — verification is a
 * separate, multi-dimensional concern (directory_verifications,
 * Mission 2 checkpoint 7); duplicating a summary flag here would be
 * exactly the "one Boolean" anti-pattern section 24 rejects.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('directory_attorneys', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->string('slug')->unique();
            $table->string('name');
            $table->string('name_normalized');
            $table->string('title')->nullable();
            $table->text('biography')->nullable();
            $table->string('photo_path')->nullable();

            $table->string('bar_number')->nullable();
            $table->json('license_jurisdictions')->nullable();

            $table->string('publication_state')->default('draft');

            $table->string('source_type');
            $table->string('source_reference')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamp('last_verified_at')->nullable();

            $table->timestamps();

            $table->index('name_normalized');
            $table->index('publication_state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('directory_attorneys');
    }
};
