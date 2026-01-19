<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('api_providers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->string('base_url');
            $table->json('headers')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('rate_limit')->default(100);
            $table->integer('requests_used')->default(0);
            $table->timestamp('reset_time')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('api_providers');
    }
};