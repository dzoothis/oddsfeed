<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('match_statistics', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('match_id'); // Reference to matches.eventId
            $table->enum('stat_type', ['yellow_card', 'red_card', 'goal', 'substitution']);
            $table->integer('minute');
            $table->string('player_name')->nullable();
            $table->string('team'); // home or away
            $table->text('details')->nullable();
            $table->timestamps();
            
            $table->index(['match_id', 'stat_type']);
            // Add foreign key constraint manually since primary key is eventId
            $table->foreign('match_id')->references('eventId')->on('matches')->onDelete('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('match_statistics');
    }
};