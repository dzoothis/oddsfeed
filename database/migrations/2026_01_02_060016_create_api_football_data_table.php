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
        Schema::create('api_football_data', function (Blueprint $table) {
            $table->unsignedBigInteger('eventId')->primary(); // 1:1 relationship
            $table->integer('fixtureId')->nullable();
            $table->json('yellowCards')->nullable();
            $table->json('redCards')->nullable();
            $table->json('incidents')->nullable();
            $table->integer('elapsedTime')->nullable();
            $table->integer('extraTime')->nullable();
            $table->json('status')->nullable();
            $table->timestamp('lastUpdated')->useCurrent();

            $table->foreign('eventId')->references('eventId')->on('matches')->onDelete('cascade');

            // Index for looking up by external fixture ID
            $table->index('fixtureId');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_football_data');
    }
};
