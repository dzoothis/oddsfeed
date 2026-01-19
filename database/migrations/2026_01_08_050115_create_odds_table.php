<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('odds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('betting_market_id')->constrained()->onDelete('cascade');
            $table->string('outcome'); // home, away, draw, over, under, etc.
            $table->decimal('odds', 6, 3);
            $table->decimal('line', 8, 2)->nullable(); // For spreads/totals
            $table->string('bookmaker')->nullable(); // For The-Odds-API
            $table->timestamps();
            
            $table->index(['betting_market_id', 'outcome']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('odds');
    }
};