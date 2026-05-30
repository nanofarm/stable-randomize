<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('owner_id');
            $table->bigInteger('chat_id')->unique();
            $table->string('title');
            $table->string('username')->nullable();
            $table->string('type')->default('channel');
            $table->integer('member_count')->default(0);
            $table->boolean('bot_is_admin')->default(false);
            $table->timestamps();

            $table->index('owner_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channels');
    }
};
