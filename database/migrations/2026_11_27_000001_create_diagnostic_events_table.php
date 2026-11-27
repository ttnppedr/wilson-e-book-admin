<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * App 診斷紀錄的單筆事件。
     *
     * unique(session, seq) 是冪等鍵：App 上傳失敗會重送同一批，靠它擋重複寫入。
     * 同一個索引也供時間軸「依 seq 排序」使用。
     */
    public function up(): void
    {
        Schema::create('diagnostic_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('diagnostic_session_id')
                ->constrained('diagnostic_sessions')
                ->cascadeOnDelete();
            $table->unsignedInteger('seq');
            $table->dateTime('occurred_at', 3);
            $table->string('category', 32);
            $table->string('name', 64);
            $table->string('level', 8);
            $table->json('data')->nullable();
            $table->dateTime('received_at', 3);

            $table->unique(['diagnostic_session_id', 'seq'], 'diagnostic_events_session_seq_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diagnostic_events');
    }
};
