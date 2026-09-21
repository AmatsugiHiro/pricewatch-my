<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('premises', function (Blueprint $table) {
            $table->unsignedInteger('premise_code')->primary();
            $table->string('premise');
            $table->text('address')->nullable();
            $table->string('premise_type', 100)->nullable();
            $table->string('state', 100)->nullable();
            $table->string('district', 100)->nullable();
            $table->timestamps();

            $table->index(['state', 'district']);
            $table->index('premise_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('premises');
    }
};
