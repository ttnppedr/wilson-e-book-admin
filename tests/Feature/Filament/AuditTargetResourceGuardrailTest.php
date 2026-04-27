<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\LicenseResource\Pages\ListLicenses;
use App\Filament\Resources\LicenseScopeResource\Pages\ListLicenseScopes;
use App\Filament\Resources\LicenseUsageResource\Pages\ListLicenseUsages;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * audit 對象 Resource 的結構守門員。
 *
 * License/LicenseScope/LicenseUsage 三個 model 透過 owen-it/laravel-auditing 記錄變更，
 * 但 audit 是掛在 Eloquent 的 created/updated/deleted 事件上——
 * 任何「不走 model」的批次操作（bulk delete、reorderable、saveQuietly）都會繞過 audit。
 *
 * 本測試在 Filament 表格層斷言這些 audit 對象 Resource 不能出現會繞過事件的設定。
 * 若有人未來在這些 Resource 加 BulkAction 或 reorderable，這組測試會立刻紅燈。
 */
class AuditTargetResourceGuardrailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    /**
     * @return iterable<string, array{class-string<\Filament\Resources\Pages\ListRecords>, string}>
     */
    public static function auditTargetListPages(): iterable
    {
        yield 'License' => [ListLicenses::class, 'License'];
        yield 'LicenseScope' => [ListLicenseScopes::class, 'LicenseScope'];
        yield 'LicenseUsage' => [ListLicenseUsages::class, 'LicenseUsage'];
    }

    #[DataProvider('auditTargetListPages')]
    public function test_audit_target_resource_has_no_toolbar_actions(string $listPage, string $modelLabel): void
    {
        $table = Livewire::test($listPage)->instance()->getTable();

        $this->assertSame(
            [],
            $table->getToolbarActions(),
            "{$modelLabel} Resource 不可有 toolbarActions（BulkAction）。"
            .'BulkAction 預設用 query builder 一次刪/改多筆，會繞過 Eloquent 事件，導致 audit 無法記錄。'
            .'若需批次操作，請實作為 record action 或在 action 內逐筆呼叫 $record->save()/->delete()。',
        );
    }

    #[DataProvider('auditTargetListPages')]
    public function test_audit_target_resource_is_not_reorderable(string $listPage, string $modelLabel): void
    {
        $table = Livewire::test($listPage)->instance()->getTable();

        $this->assertNull(
            $table->getReorderColumn(),
            "{$modelLabel} Resource 不可使用 reorderable()。"
            .'Filament 拖曳排序用 query builder mass update 寫排序欄位，會繞過 Eloquent 事件，導致 audit 無法記錄。',
        );
    }
}
