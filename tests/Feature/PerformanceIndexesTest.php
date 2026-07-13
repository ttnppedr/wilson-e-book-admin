<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * P-01：排序與 FK 欄位需有索引。PostgreSQL/SQLite 的 FK constraint
 * 不會自動建索引，須由 migration 明確建立。
 */
class PerformanceIndexesTest extends TestCase
{
    use RefreshDatabase;

    public function test_sort_and_foreign_key_columns_are_indexed(): void
    {
        $this->assertColumnIndexed('wordwalls', 'sort');
        $this->assertColumnIndexed('wordwalls', 'wordwall_category_id');
        $this->assertColumnIndexed('wordwall_categories', 'sort');
        $this->assertColumnIndexed('license_usages', 'registered_at');
    }

    private function assertColumnIndexed(string $table, string $column): void
    {
        $indexed = collect(Schema::getIndexes($table))
            ->contains(fn (array $index): bool => ($index['columns'][0] ?? null) === $column);

        $this->assertTrue($indexed, "{$table}.{$column} 應有以其為第一欄的索引。");
    }
}
