<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('watch_item_id')->constrained()->cascadeOnDelete();
            $table->date('observed_on');
            $table->decimal('observed_price', 10, 4);
            $table->decimal('threshold_price', 8, 2);
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();

            // One alert per watch per day. This is the guard that stops a re-run of
            // the aggregation pass from notifying the same user twice.
            $table->unique(['watch_item_id', 'observed_on'], 'price_alerts_daily_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_alerts');
    }
};
