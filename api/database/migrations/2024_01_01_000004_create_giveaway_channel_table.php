<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('giveaway_channel', function (Blueprint $table) {
            $table->id();
            $table->foreignId('giveaway_id')->constrained()->cascadeOnDelete();
            $table->foreignId('channel_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['giveaway_id', 'channel_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('giveaway_channel');
    }
};
