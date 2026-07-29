<?php

/**
 * LicenseScope 刪除守門。
 *
 * 守護的性質：`licenses.license_scope_id` 的外鍵是 `nullOnDelete()`，刪掉 scope 會
 * 由 **DB 引擎**把旗下所有 License 的 license_scope_id 清成 NULL。這條路徑不派發
 * Eloquent 事件，所以 CLAUDE.md 列的 5 層 audit 防護（Filament 結構守門員、
 * EnforcesAuditEvents、AST 掃描、grep script、整合測試）**全部攔不到**——它們都只
 * 看 PHP 層，沒有一層看 DB schema。後果除了無稽核軌跡，還會讓該產品線所有授權
 * 失去 content encryption key，activate 永久回 500。
 *
 * 因此守門必須落在 model 層（涵蓋所有入口），UI 只是把按鈕藏起來免得誤觸。
 */

use App\Filament\Resources\LicenseScopeResource\Pages\EditLicenseScope;
use App\Filament\Resources\LicenseScopeResource\Pages\ListLicenseScopes;
use App\Models\License;
use App\Models\LicenseScope;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use LucaLongo\Licensing\Enums\LicenseStatus;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());
});

$createScope = function (): LicenseScope {
    return LicenseScope::create([
        'name' => 'Guarded Scope',
        'is_active' => true,
        'default_max_usages' => 3,
        'default_duration_days' => 365,
    ]);
};

$attachLicense = function (LicenseScope $scope): License {
    return License::create([
        'key_hash' => hash('sha256', fake()->uuid()),
        'status' => LicenseStatus::Active,
        'license_scope_id' => $scope->getKey(),
        'name' => 'Attached License',
        'activated_at' => now(),
        'expires_at' => now()->addDays(365),
        'max_usages' => 3,
        'meta' => [],
    ]);
};

test('deleting a scope that still has licenses throws', function () use ($createScope, $attachLicense): void {
    $scope = $createScope();
    $license = $attachLicense($scope);

    $this->expectException(LogicException::class);

    try {
        $scope->delete();
    } finally {
        // 關聯必須原封不動——若例外是在 DB 動作之後才拋，資料已經毀了
        $this->assertDatabaseHas('license_scopes', ['id' => $scope->getKey()]);
        $this->assertSame($scope->getKey(), $license->fresh()->license_scope_id);
    }
});

test('deleting a scope without licenses succeeds', function () use ($createScope): void {
    $scope = $createScope();

    $this->assertTrue($scope->delete());
    $this->assertDatabaseMissing('license_scopes', ['id' => $scope->getKey()]);
});

test('deleting is still blocked after the last license is detached and reattached', function () use ($createScope, $attachLicense): void {
    $scope = $createScope();
    $license = $attachLicense($scope);

    $license->delete();
    $this->assertTrue($scope->fresh()->delete(), '沒有授權了就應該可以刪');

    $other = $createScope();
    $attachLicense($other);
    $this->expectException(LogicException::class);
    $other->fresh()->delete();
});

test('list page hides the delete action while licenses exist', function () use ($createScope, $attachLicense): void {
    $scope = $createScope();
    $attachLicense($scope);

    Livewire::test(ListLicenseScopes::class)
        ->assertActionHidden(TestAction::make('delete')->table($scope));
});

test('list page shows the delete action once no licenses remain', function () use ($createScope): void {
    $scope = $createScope();

    Livewire::test(ListLicenseScopes::class)
        ->assertActionVisible(TestAction::make('delete')->table($scope));
});

test('edit page hides the delete action while licenses exist', function () use ($createScope, $attachLicense): void {
    // 編輯頁 header 的 DeleteAction 來自 vendor 的 getHeaderActions()，
    // 與列表頁那顆是兩條獨立路徑，必須各自守門。
    $scope = $createScope();
    $attachLicense($scope);

    Livewire::test(EditLicenseScope::class, ['record' => $scope->getKey()])
        ->assertActionHidden('delete');
});

test('edit page shows the delete action once no licenses remain', function () use ($createScope): void {
    $scope = $createScope();

    Livewire::test(EditLicenseScope::class, ['record' => $scope->getKey()])
        ->assertActionVisible('delete');
});
