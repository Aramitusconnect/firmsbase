<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * platform_subscription_items — line items composing a platform
 * subscription (base plan, seat add-ons, storage add-ons). seat_class
 * nullable — only populated when item_type is a seat add-on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_subscription_items', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();

            $table->foreignId('platform_subscription_id')->constrained('platform_subscriptions')->cascadeOnDelete();
            $table->string('item_type');
            $table->string('seat_class')->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedBigInteger('unit_amount_cents');
            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->index('platform_subscription_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_subscription_items');
    }
};
