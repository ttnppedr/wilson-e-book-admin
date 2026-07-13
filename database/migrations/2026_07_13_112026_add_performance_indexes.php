<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 排序與 FK 欄位補索引：
     * - sort 欄位供 API orderBy 與後台 defaultSort/reorderable。
     * - wordwall_category_id 的 FK constraint 在 PostgreSQL/SQLite 不會自動建索引
     *   （MySQL 會），eager load 的 WHERE IN 與 nullOnDelete 都需要它。
     * - registered_at 供 LicenseUsage defaultSort 與最近啟用 widget 排序。
     */
    public function up(): void
    {
        Schema::table('wordwalls', function (Blueprint $table): void {
            $table->index('sort');
            $table->index('wordwall_category_id');
        });

        Schema::table('wordwall_categories', function (Blueprint $table): void {
            $table->index('sort');
        });

        Schema::table('license_usages', function (Blueprint $table): void {
            $table->index('registered_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wordwalls', function (Blueprint $table): void {
            $table->dropIndex(['sort']);
            $table->dropIndex(['wordwall_category_id']);
        });

        Schema::table('wordwall_categories', function (Blueprint $table): void {
            $table->dropIndex(['sort']);
        });

        Schema::table('license_usages', function (Blueprint $table): void {
            $table->dropIndex(['registered_at']);
        });
    }
};
