<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * directory_profile_versions — Mission 2 (MyAttorney Marketplace
 * Core), section 25. Lightweight public-profile versioning (changed
 * fields/actor/source/timestamp/publication state) — deliberately NOT
 * full event-sourcing (section 25's own explicit instruction). One
 * append-only row per change to a directory_firms row's public-facing
 * content, recorded by MarketplaceProfileVersionService, the sole
 * write path.
 *
 * `actor_type`/`actor_id` are plain descriptive columns, not a strict
 * Eloquent MorphTo — a version's actor can be a PlatformAdmin, a
 * FirmUser, the CSV import pipeline (checkpoint 9), or "system"
 * (no real actor row at all), so this intentionally mirrors
 * timeline_events.actor_type/actor_id's own looser convention rather
 * than DirectoryVerification.verifiable_type/verifiable_id's strict
 * morphTo (every one of THAT table's subjects is a real Eloquent
 * model; not every actor here is).
 *
 * UPDATED_AT disabled at the model layer, created_at only — append-
 * only, same as timeline_events. Global platform data, no firm_id
 * column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('directory_profile_versions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('directory_firm_id')->constrained('directory_firms')->cascadeOnDelete();

            $table->json('changed_fields');
            $table->string('actor_type')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('source');
            $table->string('publication_state');

            $table->timestamp('created_at');

            $table->index(['directory_firm_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('directory_profile_versions');
    }
};
