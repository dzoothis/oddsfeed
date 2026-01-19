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
        Schema::create('odds_api_unmatched', function (Blueprint $table) {
            $table->id();
            $table->string('odds_api_id'); // Odds API match ID
            $table->string('home_team');
            $table->string('away_team');
            $table->timestamp('commence_time');
            $table->json('match_data'); // Full Odds API match data
            $table->string('reason'); // Why it couldn't be matched
            $table->boolean('processed')->default(false); // For manual review workflow
            $table->timestamp('processed_at')->nullable();
            $table->text('notes')->nullable(); // Admin notes
            $table->timestamps();

            $table->index(['odds_api_id'], 'idx_odds_api_id');
            $table->index(['processed'], 'idx_processed');
            $table->index(['commence_time'], 'idx_commence_time');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('odds_api_unmatched');
    }
};
