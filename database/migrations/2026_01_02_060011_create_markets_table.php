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
        Schema::create('markets', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('eventId');
            $table->string('marketType', 50); // moneyLine, spreads, etc.
            $table->string('bet')->nullable();
            $table->string('teamType', 50)->nullable();
            $table->string('line', 50)->nullable();
            $table->decimal('price', 10, 3)->nullable();
            $table->string('status', 20)->nullable();
            $table->string('period', 100)->nullable();
            $table->timestamp('lastUpdated')->useCurrent();

            $table->foreign('eventId')->references('eventId')->on('matches')->onDelete('cascade');

            // Indexes for fast retrieval of markets for a specific match
            $table->index(['eventId', 'marketType']);
            $table->index('status'); // To filter only 'Open' markets
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('markets');
    }
};
