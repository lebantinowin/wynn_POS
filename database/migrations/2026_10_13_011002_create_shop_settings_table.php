<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shop_settings', function (Blueprint $table): void {
            $table->unsignedSmallInteger('id')->primary();
            $table->jsonb('snapshot');
            $table->timestampTz('updated_at');
            $table->foreign('id')->references('id')->on('pos_workspace')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_settings');
    }
};
