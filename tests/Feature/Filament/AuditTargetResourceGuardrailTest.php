<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\LicenseResource\Pages\EditLicense;
use App\Filament\Resources\LicenseResource\Pages\ListLicenses;
use App\Filament\Resources\LicenseResource\RelationManagers\UsagesRelationManager;
use App\Filament\Resources\LicenseScopeResource\Pages\EditLicenseScope;
use App\Filament\Resources\LicenseScopeResource\Pages\ListLicenseScopes;
use App\Filament\Resources\LicenseScopeResource\RelationManagers\LicensesRelationManager;
use App\Filament\Resources\LicenseUsageResource\Pages\ListLicenseUsages;
use App\Models\ContentEncryptionKey;
use App\Models\License;
use App\Models\LicenseScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use LucaLongo\Licensing\Enums\LicenseStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * audit 對象 Resource / RelationManager 的結構守門員。
 *
 * License/LicenseScope/LicenseUsage 三個 model 透過 owen-it/laravel-auditing 記錄變更，
 * 但 audit 是掛在 Eloquent 的 created/updated/deleted 事件上——
 * 任何「不走 model」的批次操作（bulk delete、reorderable、saveQuietly）都會繞過 audit。
 *
 * 本測試在 Filament 表格層斷言這些 audit 對象不能出現會繞過事件的設定，
 * 涵蓋 ListPage 與嵌入在 Edit 頁面內的 RelationManager（Filament 的 audit blind spot）。
 * 若有人未來在這些表格加 BulkAction 或 reorderable，這組測試會立刻紅燈。
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

    /**
     * @return iterable<string, array{class-string, class-string, \Closure(self): Model, string}>
     */
    public static function auditTargetRelationManagers(): iterable
    {
        yield 'License → LicenseUsage' => [
            UsagesRelationManager::class,
            EditLicense::class,
            fn (self $case): Model => $case->createLicense(),
            'License → UsagesRelationManager',
        ];

        yield 'LicenseScope → License' => [
            LicensesRelationManager::class,
            EditLicenseScope::class,
            fn (self $case): Model => $case->createLicenseScope(),
            'LicenseScope → LicensesRelationManager',
        ];
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

    #[DataProvider('auditTargetRelationManagers')]
    public function test_audit_target_relation_manager_has_no_toolbar_actions(
        string $relationManager,
        string $pageClass,
        \Closure $ownerFactory,
        string $label,
    ): void {
        $owner = $ownerFactory($this);

        $table = Livewire::test($relationManager, [
            'ownerRecord' => $owner,
            'pageClass' => $pageClass,
        ])->instance()->getTable();

        $this->assertSame(
            [],
            $table->getToolbarActions(),
            "{$label} 不可有 toolbarActions（BulkAction）。"
            .'BulkAction 會用 query builder 一次刪/改多筆，繞過 Eloquent 事件導致 audit 漏記。'
            .'RelationManager 繼承 vendor base class 時尤其要注意 — vendor 預設可能含 BulkAction，須在子類覆寫掉。',
        );
    }

    #[DataProvider('auditTargetRelationManagers')]
    public function test_audit_target_relation_manager_is_not_reorderable(
        string $relationManager,
        string $pageClass,
        \Closure $ownerFactory,
        string $label,
    ): void {
        $owner = $ownerFactory($this);

        $table = Livewire::test($relationManager, [
            'ownerRecord' => $owner,
            'pageClass' => $pageClass,
        ])->instance()->getTable();

        $this->assertNull(
            $table->getReorderColumn(),
            "{$label} 不可使用 reorderable()。"
            .'拖曳排序走 mass update，會繞過 Eloquent 事件導致 audit 漏記。',
        );
    }

    public function createLicenseScope(): LicenseScope
    {
        $cek = ContentEncryptionKey::create([
            'name' => 'Guardrail Test CEK',
            'encrypted_key' => ContentEncryptionKey::generateKey(),
        ]);

        return LicenseScope::create([
            'name' => 'Guardrail Scope',
            'is_active' => true,
            'default_max_usages' => 3,
            'default_duration_days' => 365,
            'content_encryption_key_id' => $cek->getKey(),
        ]);
    }

    public function createLicense(): License
    {
        $scope = $this->createLicenseScope();

        return License::create([
            'key_hash' => hash('sha256', fake()->uuid()),
            'status' => LicenseStatus::Active,
            'license_scope_id' => $scope->getKey(),
            'name' => 'Guardrail License',
            'activated_at' => now(),
            'expires_at' => now()->addDays(365),
            'max_usages' => 3,
            'meta' => [],
        ]);
    }
}
