<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('giveaways', function (Blueprint $table) {
            $table->json('tasks')->nullable()->after('referral_tickets');
        });

        Schema::create('giveaway_task_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('giveaway_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->integer('task_id');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->integer('tickets_awarded')->default(0);
            $table->timestamp('submitted_at')->useCurrent();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->unique(['giveaway_id', 'user_id', 'task_id']);
            $table->index(['giveaway_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('giveaway_task_submissions');
        Schema::table('giveaways', function (Blueprint $table) {
            $table->dropColumn('tasks');
        });
    }
};
