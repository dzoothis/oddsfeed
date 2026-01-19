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
        Schema::create('bet_types', function (Blueprint $table) {
            $table->id();
            $table->integer('sportId');
            $table->string('category', 100);
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('isActive')->default(true);
            $table->timestamps();

            // Unique constraint to prevent duplicates
            $table->unique(['sportId', 'category', 'name']);

            $table->index('sportId');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bet_types');
    }
};
