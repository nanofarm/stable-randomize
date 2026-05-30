<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('giveaway_draw_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('giveaway_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('algorithm', 64);
            $table->string('participant_snapshot_hash', 64);
            $table->string('winner_snapshot_hash', 64);
            $table->json('participant_snapshot');
            $table->json('winner_snapshot');
            $table->unsignedInteger('total_participants');
            $table->unsignedInteger('total_tickets');
            $table->string('draw_nonce', 64);
            $table->string('signature', 128);
            $table->string('result_token', 64)->unique();
            $table->timestamp('drawn_at');
            $table->timestamps();

            $table->index(['participant_snapshot_hash', 'winner_snapshot_hash'], 'giveaway_draw_audits_hash_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('giveaway_draw_audits');
    }
};
