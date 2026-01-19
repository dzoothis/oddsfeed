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
        Schema::create('team_provider_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->onDelete('cascade');
            $table->string('provider_name', 50); // 'pinnacle', 'odds_api', 'api_football'
            $table->string('provider_team_id', 100)->nullable(); // External provider's team ID
            $table->string('provider_team_name', 255); // External provider's team name
            $table->decimal('confidence_score', 3, 2)->default(1.00); // 0.00-1.00 confidence in mapping
            $table->boolean('is_primary')->default(false); // Is this the primary mapping for this provider?
            $table->timestamps();

            // Ensure one primary mapping per team per provider
            $table->unique(['team_id', 'provider_name', 'is_primary'], 'unique_provider_primary');

            // Ensure unique provider team IDs per provider
            $table->unique(['provider_name', 'provider_team_id'], 'unique_provider_team');

            // Indexes for fast lookups
            $table->index(['provider_name', 'provider_team_id'], 'idx_provider_lookup');
            $table->index(['team_id', 'provider_name'], 'idx_team_provider');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('team_provider_mappings');
    }
};
