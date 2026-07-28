<?php

/**
 * P-01：排序與 FK 欄位需有索引。PostgreSQL/SQLite 的 FK constraint
 * 不會自動建索引，須由 migration 明確建立。
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

test('sort and foreign key columns are indexed', function (): void {
    $assertColumnIndexed = function (string $table, string $column): void {
        $indexed = collect(Schema::getIndexes($table))
            ->contains(fn (array $index): bool => ($index['columns'][0] ?? null) === $column);

        $this->assertTrue($indexed, "{$table}.{$column} 應有以其為第一欄的索引。");
    };

    $assertColumnIndexed('wordwalls', 'sort');
    $assertColumnIndexed('wordwalls', 'wordwall_category_id');
    $assertColumnIndexed('wordwall_categories', 'sort');
    $assertColumnIndexed('license_usages', 'registered_at');
});
