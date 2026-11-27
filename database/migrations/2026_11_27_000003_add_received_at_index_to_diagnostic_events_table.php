<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `diagnostics:prune` 每晚以 received_at 找出過期事件；沒有索引就是整表掃描，
     * 而這張表是全系統成長最快的一張。
     */
    public function up(): void
    {
        Schema::table('diagnostic_events', function (Blueprint $table): void {
            $table->index('received_at', 'diagnostic_events_received_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('diagnostic_events', function (Blueprint $table): void {
            $table->dropIndex('diagnostic_events_received_at_index');
        });
    }
};
