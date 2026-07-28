<?php

/**
 * guardLicenseState() 覆寫的 feature tests。
 *
 * 驗證 cancelled 與 suspended 已經被拆成兩個獨立的 error code：
 *   - cancelled → 410 CANCELLED_LICENSE
 *   - suspended → 423 SUSPENDED_LICENSE
 *
 * 同時驗證優先順序（cancelled > suspended > expired > not_active），確保
 * 同時命中多個條件時回傳正確的錯誤碼。
 *
 * 覆寫位置：app/Http/Controllers/Api/LicenseController.php::guardLicenseState()
 * 對齊版本：vendor/masterix21/laravel-licensing/src/Http/Controllers/Api/LicenseController.php:299
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use LucaLongo\Licensing\Enums\LicenseStatus;
use LucaLongo\Licensing\Models\License;
use Tests\Concerns\MocksLicenseTokenVerifier;

uses(MocksLicenseTokenVerifier::class, RefreshDatabase::class);

$activateUrl = '/api/licensing/v1/activate';

/**
 * 建立一個指定狀態的 license row（不透過 activate 流程）。
 */
$makeLicense = function (LicenseStatus $status, ?DateTimeInterface $expiresAt = null): string {
    $licenseKey = 'TESTKEY'.bin2hex(random_bytes(6));
    License::create([
        'key_hash' => License::hashKey($licenseKey),
        'status' => $status,
        'activated_at' => $status === LicenseStatus::Pending ? null : now(),
        'expires_at' => $expiresAt ?? now()->addDays(30),
        'max_usages' => 1,
        'meta' => [],
    ]);

    return $licenseKey;
};

// -------------------------------------------------------------------------
// validate endpoint
// -------------------------------------------------------------------------

test('validate returns cancelled license for cancelled status', function () use ($makeLicense): void {
    $licenseKey = $makeLicense(LicenseStatus::Cancelled);
    $this->acceptAnyTokenFor($licenseKey, 'test-fp');

    $response = $this->postValidateWithToken([
        'license_key' => $licenseKey,
        'fingerprint' => 'test-fp',
    ]);

    $response->assertStatus(410);
    $response->assertJsonPath('error.code', 'CANCELLED_LICENSE');
});

test('validate returns suspended license for suspended status', function () use ($makeLicense): void {
    $licenseKey = $makeLicense(LicenseStatus::Suspended);
    $this->acceptAnyTokenFor($licenseKey, 'test-fp');

    $response = $this->postValidateWithToken([
        'license_key' => $licenseKey,
        'fingerprint' => 'test-fp',
    ]);

    $response->assertStatus(423);
    $response->assertJsonPath('error.code', 'SUSPENDED_LICENSE');
});

test('validate returns expired license for expired status', function () use ($makeLicense): void {
    $licenseKey = $makeLicense(LicenseStatus::Expired, now()->subDays(10));
    $this->acceptAnyTokenFor($licenseKey, 'test-fp');

    $response = $this->postValidateWithToken([
        'license_key' => $licenseKey,
        'fingerprint' => 'test-fp',
    ]);

    $response->assertStatus(410);
    $response->assertJsonPath('error.code', 'EXPIRED_LICENSE');
});

/**
 * 產品決策：無寬限期。status 仍是 active、但 expires_at 已過的授權
 * （每日排程尚未跑過）必須**當下**就被拒，不能等到 status 被改寫。
 */
test('validate rejects active license whose expires at has passed', function () use ($makeLicense): void {
    $licenseKey = $makeLicense(LicenseStatus::Active, now()->subMinute());
    $this->acceptAnyTokenFor($licenseKey, 'test-fp');

    $response = $this->postValidateWithToken([
        'license_key' => $licenseKey,
        'fingerprint' => 'test-fp',
    ]);

    $response->assertStatus(410);
    $response->assertJsonPath('error.code', 'EXPIRED_LICENSE');
});

/**
 * 舊資料殘留的 grace 授權一律拒絕——vendor 的 LicenseStatus::isUsable()
 * 仍把 Grace 算成可用，本專案不接受。
 */
test('validate rejects legacy grace license', function () use ($makeLicense): void {
    $licenseKey = $makeLicense(LicenseStatus::Grace, now()->subDay());
    $this->acceptAnyTokenFor($licenseKey, 'test-fp');

    $response = $this->postValidateWithToken([
        'license_key' => $licenseKey,
        'fingerprint' => 'test-fp',
    ]);

    $response->assertStatus(410);
    $response->assertJsonPath('error.code', 'EXPIRED_LICENSE');
});

// -------------------------------------------------------------------------
// activate endpoint
// -------------------------------------------------------------------------

test('activate returns cancelled license for cancelled status', function () use ($makeLicense, $activateUrl): void {
    $licenseKey = $makeLicense(LicenseStatus::Cancelled);

    $response = $this->postJson($activateUrl, [
        'license_key' => $licenseKey,
        'fingerprint' => 'test-fp',
        'client_ephemeral_public_key' => freshClientPublicKeyB64(),
    ]);

    $response->assertStatus(410);
    $response->assertJsonPath('error.code', 'CANCELLED_LICENSE');
});

test('activate returns suspended license for suspended status', function () use ($makeLicense, $activateUrl): void {
    $licenseKey = $makeLicense(LicenseStatus::Suspended);

    $response = $this->postJson($activateUrl, [
        'license_key' => $licenseKey,
        'fingerprint' => 'test-fp',
        'client_ephemeral_public_key' => freshClientPublicKeyB64(),
    ]);

    $response->assertStatus(423);
    $response->assertJsonPath('error.code', 'SUSPENDED_LICENSE');
});

// -------------------------------------------------------------------------
// 優先順序驗證：cancelled > suspended > expired
// -------------------------------------------------------------------------

test('cancelled takes precedence over expired check', function () use ($makeLicense): void {
    // 即使 expires_at 已經過去，cancelled 狀態的 license 仍應回報 CANCELLED_LICENSE
    // 而不是 EXPIRED_LICENSE，因為 cancelled 是更強的終態
    $licenseKey = $makeLicense(LicenseStatus::Cancelled, now()->subDays(10));
    $this->acceptAnyTokenFor($licenseKey, 'test-fp');

    $response = $this->postValidateWithToken([
        'license_key' => $licenseKey,
        'fingerprint' => 'test-fp',
    ]);

    $response->assertStatus(410);
    $response->assertJsonPath('error.code', 'CANCELLED_LICENSE');
});

test('suspended takes precedence over expired check', function () use ($makeLicense): void {
    $licenseKey = $makeLicense(LicenseStatus::Suspended, now()->subDays(10));
    $this->acceptAnyTokenFor($licenseKey, 'test-fp');

    $response = $this->postValidateWithToken([
        'license_key' => $licenseKey,
        'fingerprint' => 'test-fp',
    ]);

    $response->assertStatus(423);
    $response->assertJsonPath('error.code', 'SUSPENDED_LICENSE');
});
