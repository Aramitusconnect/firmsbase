<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * module_catalog — global reference data (installable practice-area /
 * feature modules). No uuid — internal reference table, addressed by
 * its own module_code string, not a public identifier. Not firm-scoped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('module_catalog', function (Blueprint $table) {
            $table->id();

            $table->string('module_code')->unique();
            $table->string('module_name');
            $table->string('category')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('requires_admin_approval')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('module_catalog');
    }
};
