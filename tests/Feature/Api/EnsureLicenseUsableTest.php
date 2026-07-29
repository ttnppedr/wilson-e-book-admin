<?php

/**
 * EnsureLicenseUsable middleware：內容 API 的授權現況把關。
 *
 * 守護的性質：PASETO 是 stateless 憑證，簽章有效 ≠ 授權還有效。vendor 的
 * `PasetoTokenService::verify()` 只驗簽章、signing key 狀態與 exp/nbf，不看
 * License 狀態；少了這層，授權被取消／停權／到期之後既發的 token 仍能無限期
 * 存取全部內容 API（永久授權的 token TTL 是 100 年）。
 *
 * 同時守護「不要把把關做過頭」：validate 端點刻意**不掛**這層，否則授權失效時
 * 會先被擋成 401，App 就永遠問不出 410/423 這種帶原因的錯誤碼。
 */

use App\Models\License;
use App\Models\WordwallCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use LucaLongo\Licensing\Enums\LicenseStatus;
use LucaLongo\Licensing\Enums\UsageStatus;
use LucaLongo\Licensing\Models\LicenseUsage;
use Tests\Concerns\MocksLicenseTokenVerifier;

uses(MocksLicenseTokenVerifier::class, RefreshDatabase::class);

/** 掛了 EnsureLicenseUsable 的三個內容端點 */
$contentEndpoints = [
    'wordwalls' => '/api/v1/wordwalls',
    'wordwall-categories' => '/api/v1/wordwall-categories',
];

beforeEach(function (): void {
    Cache::flush();
    $this->acceptAnyBearerToken();
});

test('usable license passes through to the content endpoint', function () use ($contentEndpoints): void {
    foreach ($contentEndpoints as $url) {
        $this->getJsonWithToken($url)->assertOk();
    }
});

test('unusable license is rejected on every content endpoint', function (LicenseStatus $status) use ($contentEndpoints): void {
    $this->bearerTokenLicense()->update(['status' => $status]);

    foreach ($contentEndpoints as $name => $url) {
        $response = $this->getJsonWithToken($url);

        $response->assertStatus(401, "{$name} 應拒絕 {$status->value} 授權");
        $response->assertJsonPath('error.code', 'INVALID_TOKEN');
    }
})->with([
    'cancelled' => LicenseStatus::Cancelled,
    'suspended' => LicenseStatus::Suspended,
    'expired' => LicenseStatus::Expired,
    'pending' => LicenseStatus::Pending,
]);

test('expired license is rejected by date even while status is still active', function () use ($contentEndpoints): void {
    // 排程尚未跑過、status 還停在 active，但 expires_at 已過。
    // 把關必須是純日期判斷，不能依賴 licensing:check-expirations。
    $this->bearerTokenLicense()->update([
        'status' => LicenseStatus::Active,
        'expires_at' => now()->subMinute(),
    ]);

    foreach ($contentEndpoints as $url) {
        $this->getJsonWithToken($url)->assertStatus(401);
    }
});

test('claims without a resolvable license are rejected', function (mixed $licenseId) use ($contentEndpoints): void {
    $this->acceptAnyBearerToken(['license_id' => $licenseId]);

    foreach ($contentEndpoints as $url) {
        $this->getJsonWithToken($url)->assertStatus(401);
    }
})->with([
    'missing claim' => null,
    'unknown id' => 999999,
]);

test('category image endpoint is guarded too', function (): void {
    $category = WordwallCategory::factory()->create();

    $this->bearerTokenLicense()->update(['status' => LicenseStatus::Cancelled]);

    $this->getJsonWithToken("/api/v1/wordwall-categories/{$category->getKey()}/image")
        ->assertStatus(401)
        ->assertJsonPath('error.code', 'INVALID_TOKEN');
});

test('validate endpoint still reports the real reason instead of a generic 401', function (): void {
    // validate 刻意不掛 EnsureLicenseUsable：App 需要知道「為什麼」被拒才能分流
    // （cancelled 清本地金鑰、suspended 顯示等待管理者恢復）。
    $licenseKey = 'GUARD'.bin2hex(random_bytes(6));
    $license = License::create([
        'key_hash' => License::hashKey($licenseKey),
        'status' => LicenseStatus::Cancelled,
        'activated_at' => now(),
        'expires_at' => now()->addDays(30),
        'max_usages' => 1,
        'meta' => [],
    ]);
    LicenseUsage::create([
        'license_id' => $license->getKey(),
        'usage_fingerprint' => 'guard-fp',
        'status' => UsageStatus::Active,
        'registered_at' => now(),
        'last_seen_at' => now(),
        'client_type' => 'desktop',
        'meta' => [],
    ]);
    $this->acceptAnyTokenFor($licenseKey, 'guard-fp');

    $this->postValidateWithToken([
        'license_key' => $licenseKey,
        'fingerprint' => 'guard-fp',
    ])
        ->assertStatus(410)
        ->assertJsonPath('error.code', 'CANCELLED_LICENSE');
});
