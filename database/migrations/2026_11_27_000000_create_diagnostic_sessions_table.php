<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * App 診斷紀錄的 session（App 一次啟動到結束）。
     *
     * 時間欄位一律毫秒精度：時間軸要算相鄰事件的間隔，秒級精度會把連續操作
     * 全部壓成 +0s。
     *
     * license_id／license_usage_id 用 nullOnDelete：授權或席位被刪除後，診斷
     * 紀錄仍保留（靠 fingerprint 仍可辨識裝置），直到保留期滿由 diagnostics:prune 清除。
     */
    public function up(): void
    {
        Schema::create('diagnostic_sessions', function (Blueprint $table): void {
            $table->id();
            $table->char('session_uuid', 32)->unique();
            $table->string('fingerprint')->index();
            $table->foreignId('license_id')->nullable()->constrained('licenses')->nullOnDelete();
            $table->foreignId('license_usage_id')->nullable()->constrained('license_usages')->nullOnDelete();
            $table->string('app_version', 32);
            $table->string('app_env', 16)->nullable();
            $table->string('platform', 32);
            $table->string('os_version', 64)->nullable();
            $table->string('device_model', 128)->nullable();
            $table->dateTime('started_at', 3);
            $table->dateTime('first_event_at', 3)->nullable();
            $table->dateTime('last_event_at', 3)->nullable()->index();
            $table->unsignedInteger('event_count')->default(0);
            $table->unsignedInteger('warn_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->string('last_error_name', 64)->nullable();
            $table->ipAddress('ip')->nullable();
            $table->timestamps(3);

            // FK constraint 在 SQLite／PostgreSQL 不會自動建索引（MySQL 會）；
            // 清單頁的授權篩選與 nullOnDelete 都需要。
            $table->index('license_id');
            $table->index('license_usage_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('diagnostic_sessions');
    }
};
