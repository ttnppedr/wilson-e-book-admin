<?php

/**
 * 後台每個頁面都必須打得開。
 *
 * 升級 Filament / Livewire 時最先壞、也最難在個別功能測試裡察覺的，是「整頁渲染
 * 失敗」——個別測試多半直接掛 Livewire component，不經過完整的 panel 路由與 layout。
 * 本檔逐一以 HTTP 請求打每個後台路由，並補上先前從未被執行過的部分：
 *   - dashboard 的三個 widget（自動 discover、登入後第一頁，渲染失敗即整頁 500）
 *   - 授權列表的「顯示授權碼」動作（管理者把授權碼交給客戶的實際途徑）
 */

use App\Filament\Resources\LicenseResource\Pages\ListLicenses;
use App\Filament\Widgets\ExpiringLicenses;
use App\Filament\Widgets\LicenseStatsOverview;
use App\Filament\Widgets\RecentLicenseActivations;
use App\Models\License;
use App\Models\LicenseScope;
use App\Models\LicenseUsage;
use App\Models\User;
use App\Models\Wordwall;
use App\Models\WordwallCategory;
use App\Services\LicenseKeyGenerator;
use Filament\Actions\Testing\TestAction;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use LucaLongo\Licensing\Enums\LicenseStatus;
use LucaLongo\Licensing\Enums\UsageStatus;

uses(RefreshDatabase::class);

$plainKey = 'SMOKE12345TESTS67890';

beforeEach(function () use ($plainKey): void {
    config(['filesystems.default' => 's3']);
    Storage::fake('s3');

    $this->actingAs(User::factory()->create());

    $this->scope = LicenseScope::create([
        'name' => 'Smoke Scope',
        'is_active' => true,
        'default_max_usages' => 1,
        'default_duration_days' => 365,
    ]);

    // 30 天內到期：同時讓 ExpiringLicenses 與統計卡片的「即將到期」有資料可渲染。
    $this->license = License::createWithKey([
        'name' => 'Smoke License',
        'status' => LicenseStatus::Active,
        'license_scope_id' => $this->scope->getKey(),
        'activated_at' => now()->subDay(),
        'expires_at' => now()->addDays(10),
        'max_usages' => 1,
    ], $plainKey);

    $this->usage = LicenseUsage::create([
        'license_id' => $this->license->getKey(),
        'usage_fingerprint' => 'smoke-device',
        'status' => UsageStatus::Active,
        'registered_at' => now()->subDay(),
        'last_seen_at' => now(),
        'client_type' => 'mobile',
        'meta' => [],
    ]);

    $category = WordwallCategory::factory()->create();
    Wordwall::factory()->create(['wordwall_category_id' => $category->getKey()]);
});

/**
 * 路由以 closure 延後求值：record id 要等 beforeEach 建好資料才知道。
 */
dataset('admin pages', [
    'dashboard' => fn (): string => '/admin',
    'licenses index' => fn (): string => '/admin/licenses',
    'licenses create' => fn (): string => '/admin/licenses/create',
    'licenses view' => fn (): string => '/admin/licenses/'.License::query()->value('id'),
    'licenses edit' => fn (): string => '/admin/licenses/'.License::query()->value('id').'/edit',
    'license scopes index' => fn (): string => '/admin/license-scopes',
    'license scopes create' => fn (): string => '/admin/license-scopes/create',
    'license scopes view' => fn (): string => '/admin/license-scopes/'.LicenseScope::query()->value('id'),
    'license scopes edit' => fn (): string => '/admin/license-scopes/'.LicenseScope::query()->value('id').'/edit',
    'license usages index' => fn (): string => '/admin/license-usages',
    'license usages view' => fn (): string => '/admin/license-usages/'.LicenseUsage::query()->value('id'),
    'license usages edit' => fn (): string => '/admin/license-usages/'.LicenseUsage::query()->value('id').'/edit',
    'wordwall categories' => fn (): string => '/admin/wordwall-categories',
    'wordwalls' => fn (): string => '/admin/wordwalls',
]);

test('every admin page renders', function (string $url): void {
    $this->get($url)->assertOk();
})->with('admin pages');

test('dashboard widgets render with live data', function (): void {
    Livewire::test(LicenseStatsOverview::class)->assertOk();

    Livewire::test(ExpiringLicenses::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$this->license]);

    Livewire::test(RecentLicenseActivations::class)
        ->assertOk()
        ->assertCanSeeTableRecords([$this->usage]);
});

test('show key action reveals the license key to the admin', function () use ($plainKey): void {
    Livewire::test(ListLicenses::class)
        ->callAction(TestAction::make('show_key')->table($this->license))
        ->assertNotified(
            Notification::make()
                ->title(__('laravel-licensing-filament-manager::license.notifications.key_retrieved'))
                ->body(__('laravel-licensing-filament-manager::license.notifications.key_value', [
                    'key' => LicenseKeyGenerator::format($plainKey),
                ]))
                ->success()
                ->persistent(),
        );
});
