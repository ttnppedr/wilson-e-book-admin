<?php

/**
 * Licensing API rate limit feature 測試。
 *
 * 驗證 `throttle:licensing-activate` 與 `throttle:licensing-validate` 兩個 named
 * limiter 確實生效:
 *   - Activate: 10 次/分鐘 (config `rate_limit.activate_per_minute`)
 *   - Validate: 60 次/分鐘 (config `rate_limit.validate_per_minute`)
 *   - 複合 key 是 sha1(IP | fingerprint):換 fingerprint 應重置計數
 *   - 超過額度回 429 + 統一 `RATE_LIMITED` error code + Retry-After header
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use LucaLongo\Licensing\Enums\LicenseStatus;
use LucaLongo\Licensing\Models\License;
use Tests\Concerns\MocksLicenseTokenVerifier;

uses(MocksLicenseTokenVerifier::class, RefreshDatabase::class);

$activateUrl = '/api/licensing/v1/activate';

/**
 * 每個測試前清一次 cache,避免 rate limit 計數被前一個測試用掉的名額污染。
 */
beforeEach(function (): void {
    Cache::flush();
});

/**
 * 建立一個 active license,只用於 validate rate limit 測試
 * (讓請求能通過 VerifyBearerToken middleware 階段)。
 */
$makeActiveLicense = function (): string {
    $licenseKey = 'RATETEST'.bin2hex(random_bytes(6));
    License::create([
        'key_hash' => License::hashKey($licenseKey),
        'status' => LicenseStatus::Active,
        'activated_at' => now(),
        'expires_at' => now()->addDays(30),
        'max_usages' => 1,
        'meta' => [],
    ]);

    return $licenseKey;
};

test('activate blocks after 10 requests per minute', function () use ($activateUrl): void {
    $body = [
        'license_key' => 'NONEXISTENT-KEY',
        'fingerprint' => 'rate-fp-activate',
        'client_ephemeral_public_key' => freshClientPublicKeyB64(),
    ];

    // 前 10 次應通過 throttle(會因為 license 不存在收到 404,但 429 絕對不該出現)
    for ($i = 1; $i <= 10; $i++) {
        $response = $this->postJson($activateUrl, $body);
        $this->assertNotSame(
            429,
            $response->status(),
            "Activate request #{$i} 不應被限流,實際狀態 {$response->status()}"
        );
    }

    // 第 11 次應被 throttle 擋下
    $response = $this->postJson($activateUrl, $body);
    $response->assertStatus(429);
    $response->assertJsonPath('error.code', 'RATE_LIMITED');
    $response->assertJsonPath('error.message', 'Too many requests');
    $this->assertNotNull($response->headers->get('Retry-After'));
});

test('validate blocks after 60 requests per minute', function () use ($makeActiveLicense): void {
    $licenseKey = $makeActiveLicense();
    $this->acceptAnyTokenFor($licenseKey, 'rate-fp-validate');

    // 前 60 次應通過 throttle(會收到 403 FINGERPRINT_MISMATCH 因為沒建 usage,
    // 但 429 不該出現)
    for ($i = 1; $i <= 60; $i++) {
        $response = $this->postValidateWithToken([
            'license_key' => $licenseKey,
            'fingerprint' => 'rate-fp-validate',
        ]);
        $this->assertNotSame(
            429,
            $response->status(),
            "Validate request #{$i} 不應被限流,實際狀態 {$response->status()}"
        );
    }

    // 第 61 次
    $response = $this->postValidateWithToken([
        'license_key' => $licenseKey,
        'fingerprint' => 'rate-fp-validate',
    ]);
    $response->assertStatus(429);
    $response->assertJsonPath('error.code', 'RATE_LIMITED');
});

test('activate rate limit is keyed by fingerprint', function () use ($activateUrl): void {
    $body = [
        'license_key' => 'NONEXISTENT-KEY',
        'fingerprint' => 'fingerprint-A',
        'client_ephemeral_public_key' => freshClientPublicKeyB64(),
    ];

    // 用 fingerprint-A 打滿 10 次
    for ($i = 1; $i <= 10; $i++) {
        $this->postJson($activateUrl, $body);
    }

    // fingerprint-A 第 11 次應被擋
    $blocked = $this->postJson($activateUrl, $body);
    $blocked->assertStatus(429);

    // 但換成 fingerprint-B,因為 rate limit key = sha1(IP|fingerprint),
    // 應有一組全新的 10 次額度
    $body['fingerprint'] = 'fingerprint-B';
    $fresh = $this->postJson($activateUrl, $body);
    $this->assertNotSame(
        429,
        $fresh->status(),
        '換 fingerprint 之後 rate limit 應重置,但仍收到 429'
    );
});
