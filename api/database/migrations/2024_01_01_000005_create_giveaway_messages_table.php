<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('giveaway_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('giveaway_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('chat_id');
            $table->bigInteger('message_id');
            $table->timestamps();
        });
    }
    public function down(): void {
        Schema::dropIfExists('giveaway_messages');
    }
};
