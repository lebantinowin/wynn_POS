<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_checkouts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('device_id');
            $table->boolean('active');
            $table->jsonb('snapshot');
            $table->bigInteger('next_sequence');
            $table->timestampTz('last_event_at')->nullable();
        });
        DB::statement('CREATE UNIQUE INDEX offline_checkout_one_active ON offline_checkouts (active) WHERE active = true');
        Schema::create('offline_sale_events', function (Blueprint $table): void {
            $table->uuid('event_id')->primary();
            $table->uuid('lease_id');
            $table->bigInteger('sequence');
            $table->string('payload_hash', 64);
            $table->jsonb('receipt');
            $table->unique(['lease_id', 'sequence']);
            $table->foreign('lease_id')->references('id')->on('offline_checkouts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_sale_events');
        Schema::dropIfExists('offline_checkouts');
    }
};
