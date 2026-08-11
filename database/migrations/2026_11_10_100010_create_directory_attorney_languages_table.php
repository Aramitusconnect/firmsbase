<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * directory_attorney_languages — Mission 2 (MyAttorney Marketplace
 * Core), section 10/14. Attorney <-> Language association.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('directory_attorney_languages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('directory_attorney_id')->constrained('directory_attorneys')->cascadeOnDelete();
            $table->foreignId('language_id')->constrained('languages')->cascadeOnDelete();
            $table->string('source_type');

            $table->timestamps();

            $table->unique(['directory_attorney_id', 'language_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('directory_attorney_languages');
    }
};
