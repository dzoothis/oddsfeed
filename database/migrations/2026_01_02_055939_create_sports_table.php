<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('sports', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('pinnacleId')->unique(); // Unique index for fast lookups
            $table->boolean('isActive')->default(true);
            $table->timestamps();

            // Index for filtering active sports
            $table->index('isActive');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sports');
    }
};
