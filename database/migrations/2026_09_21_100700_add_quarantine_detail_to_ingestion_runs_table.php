<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A quarantine count alone only says "something was wrong". Recording which
     * reference codes could not be resolved turns it into an actionable finding:
     * the August 2026 file, for example, quarantines 32,163 rows solely because 14
     * item codes in the 2023-2096 range are absent from the published lookup.
     */
    public function up(): void
    {
        Schema::table('ingestion_runs', function (Blueprint $table) {
            $table->unsignedBigInteger('rows_malformed')->default(0)->after('rows_quarantined');
            $table->json('unknown_codes')->nullable()->after('rows_malformed');
        });
    }

    public function down(): void
    {
        Schema::table('ingestion_runs', function (Blueprint $table) {
            $table->dropColumn(['rows_malformed', 'unknown_codes']);
        });
    }
};
