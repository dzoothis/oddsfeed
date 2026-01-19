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
        Schema::create('leagues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sportId')->constrained('sports')->onDelete('cascade');
            $table->string('name');
            $table->integer('pinnacleId')->nullable();
            $table->string('oddsApiKey')->nullable();
            $table->integer('apiFootballId')->nullable();
            $table->boolean('isActive')->default(true);
            $table->timestamps();

            // Compound unique key to prevent duplicates
            $table->unique(['sportId', 'pinnacleId']);

            // Indexes for common queries
            $table->index('sportId');
            $table->index('isActive');
            $table->index('oddsApiKey'); // Fast lookup by external API key
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('leagues');
    }
};
