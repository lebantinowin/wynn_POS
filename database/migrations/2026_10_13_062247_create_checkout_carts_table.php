<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('checkout_carts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->unsignedSmallInteger('shop_id')->default(1);
            $table->string('status', 20)->index();
            $table->jsonb('snapshot');
            $table->timestampsTz();
            $table->foreign('shop_id')->references('id')->on('pos_workspace')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('checkout_carts');
    }
};
