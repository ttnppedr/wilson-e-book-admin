<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('license_transfer_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('license_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transfer_id')->constrained('license_transfers')->cascadeOnDelete();

            $table->string('previous_licensable_type')->nullable();
            $table->unsignedBigInteger('previous_licensable_id')->nullable();
            $table->index(['previous_licensable_type', 'previous_licensable_id'], 'lth_prev_licensable_index');

            $table->string('new_licensable_type')->nullable();
            $table->unsignedBigInteger('new_licensable_id')->nullable();
            $table->index(['new_licensable_type', 'new_licensable_id'], 'lth_new_licensable_index');

            $table->json('previous_snapshot');
            $table->json('new_snapshot');

            $table->string('transfer_type');
            $table->string('executed_by_type')->nullable();
            $table->unsignedBigInteger('executed_by_id')->nullable();
            $table->index(['executed_by_type', 'executed_by_id'], 'lth_executed_by_index');

            $table->boolean('usages_preserved');
            $table->boolean('expiration_preserved');
            $table->boolean('activation_reset');

            $table->integer('usages_transferred_count')->default(0);
            $table->integer('usages_revoked_count')->default(0);

            $table->string('integrity_hash', 64)->unique();

            $table->timestamp('executed_at');
            $table->timestamps();

            $table->index('license_id');
            $table->index('transfer_id');
            $table->index('executed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('license_transfer_histories');
    }
};
