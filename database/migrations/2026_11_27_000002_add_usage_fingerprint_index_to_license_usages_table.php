<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 診斷上傳端點（免驗證）每次請求都以 usage_fingerprint 單獨查席位。既有的
     * unique(license_id, usage_fingerprint) 以 license_id 開頭，單查指紋用不到，
     * 沒有這個索引就是每個請求一次全表掃描。
     */
    public function up(): void
    {
        Schema::table('license_usages', function (Blueprint $table): void {
            $table->index('usage_fingerprint');
        });
    }

    public function down(): void
    {
        Schema::table('license_usages', function (Blueprint $table): void {
            $table->dropIndex(['usage_fingerprint']);
        });
    }
};
