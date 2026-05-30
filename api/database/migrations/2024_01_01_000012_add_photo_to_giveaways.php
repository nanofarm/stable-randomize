<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('giveaways', function (Blueprint $table) {
            if (!Schema::hasColumn('giveaways', 'photo_path')) {
                $table->string('photo_path')->nullable()->after('description');
            }
        });
    }
    public function down(): void {
        Schema::table('giveaways', function (Blueprint $table) {
            $table->dropColumn('photo_path');
        });
    }
};
