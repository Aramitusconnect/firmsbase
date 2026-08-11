<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * directory_marketplace_analytics_events — Mission 2 (MyAttorney
 * Marketplace Core), checkpoint 13 ("privacy-conscious aggregate
 * analytics" — the design doc's own one-line description of this
 * table). Append-only, no uuid — mirrors product_analytics_events/
 * security_events' own "high-volume internal event stream, never
 * addressed individually by a public identifier" shape.
 *
 * "Privacy-conscious" is enforced by what this table deliberately
 * has NO column for, not by redaction after the fact:
 *  - no IP address (this codebase has no existing IP-hashing/
 *    truncation utility anywhere, and a raw or even hashed/truncated
 *    IP is still a re-identifiable signal — the simplest genuinely
 *    privacy-conscious choice is to never collect it at all);
 *  - no session id, cookie id, user agent, or referrer — MyAttorney's
 *    read-only routes deliberately carry no session/cookie today (see
 *    routes/web.php's own docblock, section 63/78) and this table does
 *    not introduce a reason to add one;
 *  - no actor_type/actor_id — every row is an anonymous, unlinkable
 *    occurrence, not tied to a visitor identity of any kind.
 * What's left is exactly enough for real product-usage aggregate
 * reporting (which listings get viewed, what people search for) with
 * nothing that could re-identify who did the viewing/searching.
 *
 * `subject_type`/`subject_id` are nullable — set for a profile-view
 * event (the DirectoryFirm/DirectoryAttorney viewed), null for a
 * search-performed event (a search isn't about one subject).
 * `dimensions` holds only coarse, structured search facets already
 * public in the marketplace's own taxonomy (practice area slug, city/
 * state, language, consultation mode) — never the free-text name query
 * a visitor typed, which could incidentally contain someone's name or
 * other identifying text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('directory_marketplace_analytics_events', function (Blueprint $table) {
            $table->id();

            $table->string('event_type');
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('dimensions')->nullable();

            $table->timestamp('occurred_at')->useCurrent();
            $table->timestamp('created_at')->useCurrent();

            $table->index('event_type');
            $table->index(['subject_type', 'subject_id']);
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('directory_marketplace_analytics_events');
    }
};
