<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('giveaways', function (Blueprint $table) {
            $table->string('nickname_condition')->nullable()->after('photo_path');
            $table->unsignedInteger('nickname_bonus_multiplier')->default(10)->after('nickname_condition');
            $table->unsignedInteger('referral_tickets')->default(1)->after('nickname_bonus_multiplier');
        });

        Schema::table('participants', function (Blueprint $table) {
            $table->unsignedInteger('tickets')->default(1)->after('source_channel_id');
            $table->boolean('nickname_bonus')->default(false)->after('tickets');
        });
    }

    public function down(): void
    {
        Schema::table('giveaways', function (Blueprint $table) {
            $table->dropColumn(['nickname_condition', 'nickname_bonus_multiplier', 'referral_tickets']);
        });

        Schema::table('participants', function (Blueprint $table) {
            $table->dropColumn(['tickets', 'nickname_bonus']);
        });
    }
};
