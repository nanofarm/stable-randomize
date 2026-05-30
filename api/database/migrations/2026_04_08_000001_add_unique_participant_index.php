<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    
    public function up(): void
    {
        Schema::table('giveaways', function (Blueprint $table) {
            if (!$this->indexExists('giveaways', 'giveaways_creator_id_index')) {
                $table->index('creator_id');
            }
            if (!$this->indexExists('giveaways', 'giveaways_end_date_index')) {
                $table->index('end_date');
            }
            if (!$this->indexExists('giveaways', 'giveaways_status_created_at_index')) {
                $table->index(['status', 'created_at']);
            }
        });

        Schema::table('participants', function (Blueprint $table) {
            if (!$this->indexExists('participants', 'participants_referred_by_index')) {
                $table->index('referred_by');
            }
            if (!$this->indexExists('participants', 'participants_ip_address_index')) {
                $table->index('ip_address');
            }
        });
    }

    public function down(): void
    {
        Schema::table('giveaways', function (Blueprint $table) {
            $table->dropIndex('giveaways_creator_id_index');
            $table->dropIndex('giveaways_end_date_index');
            $table->dropIndex('giveaways_status_created_at_index');
        });
        Schema::table('participants', function (Blueprint $table) {
            $table->dropIndex('participants_referred_by_index');
            $table->dropIndex('participants_ip_address_index');
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $conn = DB::connection();
        $database = $conn->getDatabaseName();
        $result = $conn->select(
            "SELECT COUNT(1) AS cnt FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ? AND index_name = ?",
            [$database, $table, $indexName]
        );
        return ($result[0]->cnt ?? 0) > 0;
    }
};
