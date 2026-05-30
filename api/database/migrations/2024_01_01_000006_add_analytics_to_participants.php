<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('participants', function (Blueprint $table) {
            $table->string('language_code', 10)->nullable()->after('user_name');
            $table->boolean('is_premium')->default(false)->after('language_code');
            $table->string('ip_address', 45)->nullable()->after('is_premium');
            $table->string('country', 2)->nullable()->after('ip_address');
            $table->string('city', 100)->nullable()->after('country');
            $table->bigInteger('referred_by')->nullable()->after('city');
            $table->string('source', 50)->nullable()->after('referred_by');
            $table->string('user_agent', 500)->nullable()->after('source');
            $table->timestamp('account_created_at')->nullable()->after('user_agent');
        });

        Schema::table('participants', function (Blueprint $table) {
            $table->index('country');
            $table->index('language_code');
            $table->index('referred_by');
            $table->index('source');
            $table->index('is_premium');
        });
    }
    public function down(): void {
        Schema::table('participants', function (Blueprint $table) {
            $table->dropColumn([
                'language_code', 'is_premium', 'ip_address', 'country', 'city',
                'referred_by', 'source', 'user_agent', 'account_created_at',
            ]);
        });
    }
};
