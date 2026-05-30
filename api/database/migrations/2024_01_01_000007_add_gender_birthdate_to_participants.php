<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('participants', function (Blueprint $table) {
            $table->string('gender', 10)->nullable()->after('is_premium');
            $table->date('birthdate')->nullable()->after('gender');
            $table->unsignedInteger('age')->nullable()->after('birthdate');
        });
    }
    public function down(): void {
        Schema::table('participants', function (Blueprint $table) {
            $table->dropColumn(['gender', 'birthdate', 'age']);
        });
    }
};
