<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * directory_attorney_firm — Mission 2 (MyAttorney Marketplace Core),
 * section 11. The explicit Attorney↔Firm relationship, with state
 * (current/former/pending_verification/disputed/unpublished). Exactly
 * one row per (directory_attorney_id, directory_firm_id) pair — an
 * attorney moving firms, or later returning to one, transitions this
 * row's state/dates rather than spawning a duplicate relationship or a
 * duplicate attorney record (section 11's own explicit instruction).
 *
 * `firm_office_id` is nullable — a relationship may exist before any
 * office context is known, or the attorney may not be tied to one
 * specific office. Global platform data, same RLS-exemption reasoning
 * as directory_firms.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('directory_attorney_firm', function (Blueprint $table) {
            $table->id();

            $table->foreignId('directory_attorney_id')->constrained('directory_attorneys')->cascadeOnDelete();
            $table->foreignId('directory_firm_id')->constrained('directory_firms')->cascadeOnDelete();
            $table->foreignId('firm_office_id')->nullable()->constrained('firm_offices')->nullOnDelete();

            $table->string('relationship_state')->default('current');
            $table->string('title')->nullable();
            $table->boolean('is_primary_firm')->default(false);
            $table->date('started_at')->nullable();
            $table->date('ended_at')->nullable();

            $table->string('source_type');
            $table->string('source_reference')->nullable();

            $table->timestamps();

            $table->unique(['directory_attorney_id', 'directory_firm_id']);
            $table->index(['directory_firm_id', 'relationship_state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('directory_attorney_firm');
    }
};
