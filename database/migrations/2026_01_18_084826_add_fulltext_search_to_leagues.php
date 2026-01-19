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
        Schema::table('leagues', function (Blueprint $table) {
            // Add FULLTEXT index for search optimization
            $table->fullText(['name'], 'leagues_name_fulltext');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('leagues', function (Blueprint $table) {
            //
        });
    }
};
