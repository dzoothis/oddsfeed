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
        Schema::create('match_enrichments', function (Blueprint $table) {
            $table->id();
            $table->string('match_id')->unique(); // Pinnacle match ID
            $table->string('venue_name')->nullable();
            $table->string('venue_city')->nullable();
            $table->string('country')->nullable();
            $table->string('source')->default('api-football');
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['match_id', 'source']);
            $table->index('last_synced_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('match_enrichments');
    }
};
