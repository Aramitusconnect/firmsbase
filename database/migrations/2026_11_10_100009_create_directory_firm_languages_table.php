<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * directory_firm_languages — Mission 2 (MyAttorney Marketplace Core),
 * section 8/14. Firm <-> Language association.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('directory_firm_languages', function (Blueprint $table) {
            $table->id();

            $table->foreignId('directory_firm_id')->constrained('directory_firms')->cascadeOnDelete();
            $table->foreignId('language_id')->constrained('languages')->cascadeOnDelete();
            $table->string('source_type');

            $table->timestamps();

            $table->unique(['directory_firm_id', 'language_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('directory_firm_languages');
    }
};
