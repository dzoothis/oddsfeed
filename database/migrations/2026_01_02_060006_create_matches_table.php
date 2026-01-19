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
        Schema::create('matches', function (Blueprint $table) {
            $table->unsignedBigInteger('eventId')->primary(); // Primary Key
            $table->integer('sportId');
            $table->integer('leagueId')->nullable();
            $table->string('leagueName')->nullable();
            $table->string('homeTeam');
            $table->string('awayTeam');
            $table->string('eventType', 20); // 'live' or 'prematch'
            $table->timestamp('startTime')->nullable();
            $table->string('liveScore', 50)->nullable();
            $table->integer('matchStatus')->nullable();
            $table->string('periodDescription', 100)->nullable();
            $table->json('cornerCount')->nullable();
            $table->json('redCards')->nullable();
            $table->boolean('hasOpenMarkets')->default(false);
            $table->timestamp('lastUpdated')->useCurrent();
            $table->timestamp('createdAt')->useCurrent();

            // Optimized Indexes
            $table->index(['sportId', 'eventType']); // Core filter for dashboard
            $table->index('leagueId');
            $table->index('startTime'); // For sorting by time
            $table->index('lastUpdated'); // For checking stale data
            $table->index('hasOpenMarkets'); // To quickly find bettable matches
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('matches');
    }
};
