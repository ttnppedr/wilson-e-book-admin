<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. 移除 licenses 的 template_id 外鍵與欄位
        Schema::table('licenses', function (Blueprint $table) {
            $table->dropForeign(['template_id']);
            $table->dropIndex(['template_id']);
            $table->dropColumn('template_id');
        });

        // 2. 刪除 license_templates 資料表
        Schema::dropIfExists('license_templates');

        // 3. license_scopes 加入 content_encryption_key_id（不加外鍵約束）
        Schema::table('license_scopes', function (Blueprint $table) {
            $table->unsignedBigInteger('content_encryption_key_id')->nullable()->after('default_grace_days');
        });
    }

    public function down(): void
    {
        Schema::table('license_scopes', function (Blueprint $table) {
            $table->dropColumn('content_encryption_key_id');
        });

        // 重建 license_templates 表（結構對應原始 migration）
        Schema::create('license_templates', function (Blueprint $table) {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('license_scope_id')->nullable()->constrained('license_scopes')->nullOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->integer('tier_level')->default(1);
            $table->foreignId('parent_template_id')->nullable()->constrained('license_templates')->nullOnDelete();
            $table->json('base_configuration')->nullable();
            $table->json('features')->nullable();
            $table->json('entitlements')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('supports_trial')->nullable();
            $table->unsignedInteger('trial_duration_days')->nullable();
            $table->boolean('has_grace_period')->nullable();
            $table->unsignedInteger('grace_period_days')->nullable();
            $table->unsignedInteger('license_duration_days')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
            $table->index(['license_scope_id', 'tier_level']);
            $table->index(['license_scope_id', 'is_active']);
        });

        Schema::table('licenses', function (Blueprint $table) {
            $table->foreignId('template_id')->nullable()->after('licensable_id')
                ->constrained('license_templates')->nullOnDelete();
            $table->index('template_id');
        });
    }
};
