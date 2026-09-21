<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pre-aggregated rollup: one row per (date, item, state) rather than one row per
     * observation. This is what every chart and table in the UI reads.
     *
     * A month of raw observations is ~1.6M rows; the same month rolled up is roughly
     * 500 items x 16 states x 31 days. Charting from here turns a multi-second scan
     * of the fact table into an indexed read of a few hundred rows.
     *
     * sample_count is stored so a national figure can be a correctly weighted mean of
     * the state rows rather than an average of averages, which would silently give
     * Perlis the same influence as Selangor.
     */
    public function up(): void
    {
        Schema::create('daily_item_state_prices', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->unsignedInteger('item_code');
            $table->string('state', 100);
            $table->decimal('min_price', 8, 2);
            $table->decimal('max_price', 8, 2);
            $table->decimal('avg_price', 10, 4);
            $table->unsignedInteger('sample_count');

            $table->unique(['date', 'item_code', 'state'], 'daily_item_state_unique');
            $table->index(['item_code', 'state', 'date'], 'daily_item_state_series_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_item_state_prices');
    }
};
