<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('phone_verifications', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id')->unique();
            $table->boolean('is_russian')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('phone_verifications');
    }
};
