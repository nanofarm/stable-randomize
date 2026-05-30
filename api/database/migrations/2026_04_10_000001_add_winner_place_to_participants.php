<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('participants', function (Blueprint $table) {
            $table->unsignedSmallInteger('winner_place')->nullable()->after('is_winner');
            $table->index(['giveaway_id', 'is_winner']);
        });
    }

    public function down(): void
    {
        Schema::table('participants', function (Blueprint $table) {
            $table->dropIndex(['giveaway_id', 'is_winner']);
            $table->dropColumn('winner_place');
        });
    }
};
