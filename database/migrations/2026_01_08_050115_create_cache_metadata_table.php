<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('cache_metadata', function (Blueprint $table) {
            $table->id();
            $table->string('cache_key')->unique();
            $table->string('endpoint');
            $table->json('params')->nullable();
            $table->timestamp('cached_at');
            $table->integer('ttl_seconds');
            $table->timestamp('expires_at');
            $table->timestamps();
            
            $table->index(['expires_at']);
        });
    }

    public function down()
    {
        Schema::dropIfExists('cache_metadata');
    }
};