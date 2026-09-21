<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('watch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('item_code');
            // A null state means "track the national weighted average".
            $table->string('state', 100)->nullable();
            $table->decimal('threshold_price', 8, 2);
            $table->string('direction', 10)->default('below');
            $table->timestamp('last_notified_at')->nullable();
            $table->timestamps();

            $table->foreign('item_code')->references('item_code')->on('items')->cascadeOnDelete();

            // Note: this unique index cannot fully enforce "one national watch per
            // item", because SQL treats every NULL as distinct inside a unique index.
            // That remaining case has to be enforced in the application layer when
            // the watchlist UI is built, rather than with a MySQL-only generated
            // column, so the schema stays portable enough to run under SQLite in CI.
            $table->unique(['user_id', 'item_code', 'state'], 'watch_items_user_item_state_unique');
            $table->index(['item_code', 'state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('watch_items');
    }
};
