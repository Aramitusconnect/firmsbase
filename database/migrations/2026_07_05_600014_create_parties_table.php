<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * parties — matter-level parties (opposing/related parties, companies,
 * witnesses, etc.), distinct from contacts/clients. entity_type
 * distinguishes an individual from a company party — no separate
 * companies table (project rule: conflict checks must cover companies,
 * which a Company-typed party satisfies).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parties', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('firm_id')->constrained('firms')->cascadeOnDelete();

            $table->string('name');
            $table->string('entity_type')->default('individual');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('company')->nullable();

            $table->text('normalized_search_keys')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index('firm_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parties');
    }
};
