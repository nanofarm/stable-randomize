<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('giveaways', function (Blueprint $table) {
            $table->string('photo_file_id')->nullable()->after('photo_path');
        });
    }

    public function down(): void
    {
        Schema::table('giveaways', function (Blueprint $table) {
            $table->dropColumn('photo_file_id');
        });
    }
};
