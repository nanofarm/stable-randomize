<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('channels', function (Blueprint $table) {
            if (!Schema::hasColumn('channels', 'invite_link')) {
                $table->string('invite_link')->nullable()->after('bot_is_admin');
            }
        });
    }
    public function down(): void {
        Schema::table('channels', function (Blueprint $table) { $table->dropColumn('invite_link'); });
    }
};
