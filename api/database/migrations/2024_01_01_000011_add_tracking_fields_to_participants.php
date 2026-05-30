<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('participants', function (Blueprint $table) {
            if (!Schema::hasColumn('participants', 'source_channel_id')) {
                $table->bigInteger('source_channel_id')->nullable()->after('source');
            }
            if (!Schema::hasColumn('participants', 'username')) {
                $table->string('username', 100)->nullable()->after('user_name');
            }
        });
    }
    public function down(): void {
        Schema::table('participants', function (Blueprint $table) {
            $table->dropColumn(['source_channel_id', 'username']);
        });
    }
};
