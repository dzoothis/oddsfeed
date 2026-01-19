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
        Schema::table('teams', function (Blueprint $table) {
            // Add Pinnacle-specific columns
            $table->string('pinnacle_team_id', 100)->nullable()->unique();
            $table->timestamp('last_pinnacle_sync')->nullable();
            $table->decimal('mapping_confidence', 3, 2)->default(1.00);

            // Add index for Pinnacle team ID lookups
            $table->index('pinnacle_team_id', 'idx_pinnacle_team_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            // Drop index first
            $table->dropIndex('idx_pinnacle_team_id');

            // Drop columns
            $table->dropColumn(['pinnacle_team_id', 'last_pinnacle_sync', 'mapping_confidence']);
        });
    }
};
