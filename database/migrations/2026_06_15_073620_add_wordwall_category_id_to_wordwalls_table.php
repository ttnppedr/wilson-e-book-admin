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
        Schema::table('wordwalls', function (Blueprint $table) {
            $table->foreignId('wordwall_category_id')
                ->nullable()
                ->after('resource_url')
                ->constrained()
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('wordwalls', function (Blueprint $table) {
            $table->dropConstrainedForeignId('wordwall_category_id');
        });
    }
};
