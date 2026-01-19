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
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sportId')->constrained('sports')->onDelete('cascade');
            $table->foreignId('leagueId')->nullable()->constrained('leagues')->onDelete('cascade');
            $table->string('name');
            $table->string('pinnacleName');
            $table->string('oddsApiName')->nullable();
            $table->string('apiFootballName')->nullable();
            $table->boolean('isActive')->default(true);
            $table->timestamps();

            // Ensure unique team per league
            $table->unique(['sportId', 'leagueId', 'pinnacleName']);

            // Indexes for search and filtering
            $table->index('sportId');
            $table->index('leagueId');
            $table->index('pinnacleName'); // For text search
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('teams');
    }
};
