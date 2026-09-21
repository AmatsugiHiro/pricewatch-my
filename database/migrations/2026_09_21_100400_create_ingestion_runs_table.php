<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An audit trail for every ingestion attempt.
     *
     * peak_memory_bytes is recorded specifically to evidence that a 48 MB source file
     * is processed in roughly constant memory rather than being read into an array,
     * and source_checksum lets a re-run detect that the upstream file is unchanged.
     */
    public function up(): void
    {
        Schema::create('ingestion_runs', function (Blueprint $table) {
            $table->id();
            $table->string('dataset', 50);
            $table->string('period', 7)->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('rows_read')->default(0);
            $table->unsignedBigInteger('rows_upserted')->default(0);
            $table->unsignedBigInteger('rows_quarantined')->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedBigInteger('peak_memory_bytes')->nullable();
            $table->string('source_checksum', 64)->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['dataset', 'period']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingestion_runs');
    }
};
