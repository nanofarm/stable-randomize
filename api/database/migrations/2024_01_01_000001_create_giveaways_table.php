<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('giveaways', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 12)->unique()->index();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('prize')->nullable();
            $table->unsignedInteger('winners_count')->default(1);
            $table->bigInteger('creator_id');
            $table->string('creator_name');
            $table->enum('status', ['active', 'finished'])->default('active')->index();
            $table->timestamp('end_date')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('giveaways');
    }
};
