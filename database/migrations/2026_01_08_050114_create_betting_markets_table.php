<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('betting_markets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('match_id'); // Reference to matches.eventId
            $table->string('provider'); // pinnacle, api_football, the_odds_api
            $table->string('market_type'); // money_line, spreads, totals, player_props, etc.
            $table->string('period')->default('FT'); // FT, HT, Q1, etc.
            $table->boolean('is_live')->default(false);
            $table->boolean('is_open')->default(true);
            $table->decimal('max_bet_limit', 10, 2)->nullable();
            $table->json('market_data');
            $table->timestamp('last_updated')->nullable();
            $table->timestamps();
            
            $table->index(['match_id', 'market_type']);
            // Add foreign key constraint manually since primary key is eventId
            $table->foreign('match_id')->references('eventId')->on('matches')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('betting_markets');
    }
};