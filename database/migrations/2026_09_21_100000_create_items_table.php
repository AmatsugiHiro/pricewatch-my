<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            // item_code is the natural key published by data.gov.my. Reusing it as the
            // primary key keeps ingestion upserts idempotent without a lookup round-trip.
            $table->unsignedInteger('item_code')->primary();
            $table->string('item');
            $table->string('unit', 50)->nullable();
            $table->string('item_group', 100)->nullable();
            $table->string('item_category', 100)->nullable();
            $table->timestamps();

            $table->index('item_group');
            $table->index('item_category');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
