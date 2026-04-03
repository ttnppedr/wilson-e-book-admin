<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('license_templates', function (Blueprint $table) {
            $table->foreignId('content_encryption_key_id')
                ->nullable()
                ->after('tier_level')
                ->constrained('content_encryption_keys')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('license_templates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('content_encryption_key_id');
        });
    }
};
