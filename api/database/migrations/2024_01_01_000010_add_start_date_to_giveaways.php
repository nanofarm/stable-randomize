<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('giveaways', function (Blueprint $table) {
            $table->timestamp('start_date')->nullable()->after('end_date');
        });
    }
    public function down(): void {
        Schema::table('giveaways', function (Blueprint $table) {
            $table->dropColumn('start_date');
        });
    }
};
