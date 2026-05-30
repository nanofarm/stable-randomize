<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('prizes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('giveaway_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('place');
            $table->string('title');
            $table->timestamps();

            $table->unique(['giveaway_id', 'place']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('prizes');
    }
};
