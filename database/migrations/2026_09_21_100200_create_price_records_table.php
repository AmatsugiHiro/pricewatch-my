<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The fact table. Roughly 1.6 million rows land here per month of PriceCatcher
     * data, so every column and index on it is a deliberate trade-off:
     *
     *  - No created_at/updated_at. Two DATETIME columns cost ~16 bytes per row, which
     *    is a few hundred megabytes across a multi-year table, and nothing in the
     *    product reads them. The ingestion_runs table records when data arrived.
     *
     *  - No foreign keys to items/premises. InnoDB validates every foreign key on
     *    every inserted row; across a bulk upsert of 1.6M rows that is real overhead
     *    for a guarantee we already enforce upstream. The ingestion pipeline checks
     *    each row against the lookup tables and quarantines orphans instead, which
     *    also produces a data-quality report that a foreign key alone could not.
     *    The logical relationships are still documented in docs/erd.md.
     *
     *  - Exactly two indexes. The unique key makes re-ingesting a month idempotent
     *    and serves date-range scans during aggregation; the (item_code, date) index
     *    serves per-item lookups. Every additional index taxes all 1.6M writes.
     */
    public function up(): void
    {
        Schema::create('price_records', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->unsignedInteger('premise_code');
            $table->unsignedInteger('item_code');
            $table->decimal('price', 8, 2);

            $table->unique(['date', 'premise_code', 'item_code'], 'price_records_natural_unique');
            $table->index(['item_code', 'date'], 'price_records_item_date_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_records');
    }
};
