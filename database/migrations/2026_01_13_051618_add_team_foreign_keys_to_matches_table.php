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
        Schema::table('matches', function (Blueprint $table) {
            // Add nullable FK columns for gradual migration
            $table->foreignId('home_team_id')->nullable()->constrained('teams')->onDelete('set null');
            $table->foreignId('away_team_id')->nullable()->constrained('teams')->onDelete('set null');

            // Add indexes for performance
            $table->index('home_team_id', 'idx_home_team');
            $table->index('away_team_id', 'idx_away_team');
            $table->index(['home_team_id', 'away_team_id'], 'idx_team_pair');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            // Drop foreign key constraints first
            $table->dropForeign(['home_team_id']);
            $table->dropForeign(['away_team_id']);

            // Drop indexes
            $table->dropIndex('idx_home_team');
            $table->dropIndex('idx_away_team');
            $table->dropIndex('idx_team_pair');

            // Drop columns
            $table->dropColumn(['home_team_id', 'away_team_id']);
        });
    }
};
