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
        Schema::create('team_enrichments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->onDelete('cascade');
            $table->string('logo_url')->nullable();
            $table->string('venue_name')->nullable();
            $table->string('venue_city')->nullable();
            $table->string('country')->nullable();
            $table->string('source')->default('api-football');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            // Ensure one enrichment per team per source
            $table->unique(['team_id', 'source']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('team_enrichments');
    }
};
