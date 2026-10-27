<?php

/**
 * LogApiCall middleware 的記錄策略測試。
 *
 * 策略：GET 成功回應（含 304）不記 response body、敏感 header
 * （authorization / cookie）一律遮蔽；錯誤（>= 400）與寫入類請求
 * 保留完整 body 供除錯。
 *
 * 304 的細節：SetResponseEtag 轉 304 時 Symfony 只清 HTTP content，
 * JsonResponse 內部 $data 仍是全量，故必須在 middleware 端擋掉。
 */

use App\Models\License;
use App\Models\LicenseUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LucaLongo\Licensing\Enums\LicenseStatus;
use LucaLongo\Licensing\Enums\UsageStatus;
use Tests\Concerns\CreatesLicensingKeys;
use Tests\Concerns\MocksLicenseTokenVerifier;

uses(CreatesLicensingKeys::class, MocksLicenseTokenVerifier::class, RefreshDatabase::class);

beforeEach(function (): void {
    $this->logPath = storage_path('logs/testing-api-'.getmypid().'.log');
    config(['logging.channels.api' => [
        'driver' => 'single',
        'path' => $this->logPath,
        'level' => 'info',
    ]]);

    if (file_exists($this->logPath)) {
        unlink($this->logPath);
    }
});

afterEach(function (): void {
    if (isset($this->logPath) && file_exists($this->logPath)) {
        unlink($this->logPath);
    }
});

/**
 * 讀出最後一筆 api log 的 JSON payload。
 *
 * @return array<string, mixed>
 */
$lastLogEntry = function (string $logPath): array {
    $this->assertFileExists($logPath);

    $lines = array_values(array_filter(explode("\n", trim(file_get_contents($logPath)))));
    $lastLine = end($lines);

    // log line 格式為 "[timestamp] env.INFO: {json}"
    $decoded = json_decode(substr($lastLine, strpos($lastLine, '{')), true);
    $this->assertIsArray($decoded);

    return $decoded;
};

test('get success omits response body and masks sensitive headers', function () use ($lastLogEntry): void {
    $this->acceptAnyBearerToken();

    $this->getJsonWithToken('/api/v1/wordwall-categories')->assertOk();

    $entry = $lastLogEntry->call($this, $this->logPath);
    $this->assertSame(200, $entry['status']);
    $this->assertNull($entry['response']);
    $this->assertArrayNotHasKey('authorization', $entry['headers']);
    $this->assertArrayNotHasKey('cookie', $entry['headers']);
    // 其餘 headers 仍要保留供除錯
    $this->assertArrayHasKey('host', $entry['headers']);
});

test('get 304 omits response body', function () use ($lastLogEntry): void {
    $this->acceptAnyBearerToken();

    $first = $this->getJsonWithToken('/api/v1/wordwall-categories');
    $etag = $first->headers->get('ETag');
    $this->assertNotNull($etag);

    $this->withHeaders([
        'Authorization' => 'Bearer stub-bearer-token',
        'If-None-Match' => $etag,
    ])->getJson('/api/v1/wordwall-categories')->assertStatus(304);

    $entry = $lastLogEntry->call($this, $this->logPath);
    $this->assertSame(304, $entry['status']);
    $this->assertNull($entry['response']);
});

test('error response body is still logged', function () use ($lastLogEntry): void {
    // 不帶 Authorization → VerifyBearerToken 回 401，屬 >= 400 應保留完整 body
    $this->getJson('/api/v1/wordwall-categories')->assertStatus(401);

    $entry = $lastLogEntry->call($this, $this->logPath);
    $this->assertSame(401, $entry['status']);
    $this->assertSame('INVALID_TOKEN', $entry['response']['error']['code'] ?? null);
});

test('non get success response body is still logged', function () use ($lastLogEntry): void {
    // validate 成功時會真實簽發驗證收據 token，需要 root/signing key
    $this->createLicensingKeys('log-api-call-test-passphrase');

    $licenseKey = 'LOGTEST'.bin2hex(random_bytes(6));
    $license = License::create([
        'key_hash' => License::hashKey($licenseKey),
        'status' => LicenseStatus::Active,
        'activated_at' => now(),
        'expires_at' => now()->addDays(30),
        'max_usages' => 1,
        'meta' => [],
    ]);
    LicenseUsage::create([
        'license_id' => $license->getKey(),
        'usage_fingerprint' => 'log-fp',
        'status' => UsageStatus::Active,
        'registered_at' => now(),
        'last_seen_at' => now(),
        'client_type' => 'desktop',
        'meta' => [],
    ]);
    $this->acceptAnyTokenFor($licenseKey, 'log-fp');

    $this->postValidateWithToken([
        'license_key' => $licenseKey,
        'fingerprint' => 'log-fp',
    ])->assertOk();

    $entry = $lastLogEntry->call($this, $this->logPath);
    $this->assertSame(200, $entry['status']);
    $this->assertTrue($entry['response']['success'] ?? false);
});

test('credential material never reaches the log', function () use ($lastLogEntry): void {
    // validate 成功會真實簽發驗證收據 token，需要 root/signing key
    $this->createLicensingKeys('log-api-call-redaction-passphrase');

    $licenseKey = 'LOGSECRET'.bin2hex(random_bytes(6));
    $license = License::create([
        'key_hash' => License::hashKey($licenseKey),
        'status' => LicenseStatus::Active,
        'activated_at' => now(),
        'expires_at' => now()->addDays(30),
        'max_usages' => 1,
        'meta' => [],
    ]);
    LicenseUsage::create([
        'license_id' => $license->getKey(),
        'usage_fingerprint' => 'redact-fp',
        'status' => UsageStatus::Active,
        'registered_at' => now(),
        'last_seen_at' => now(),
        'client_type' => 'desktop',
        'meta' => [],
    ]);
    $this->acceptAnyTokenFor($licenseKey, 'redact-fp');

    $this->postValidateWithToken([
        'license_key' => $licenseKey,
        'fingerprint' => 'redact-fp',
    ])->assertOk();

    // 對整份 log 原文斷言，而不是只看解析後的特定欄位——憑證只要在**任何**
    // 位置（含日後新增的巢狀欄位）落地就算失守。
    $raw = file_get_contents($this->logPath);
    $this->assertStringNotContainsString($licenseKey, $raw, '明文授權碼不得寫入 log');
    $this->assertStringNotContainsString('v4.public.', $raw, '簽發的 PASETO token 不得寫入 log');
    $this->assertStringContainsString('"license_key":"[REDACTED]"', $raw);
    $this->assertStringContainsString('"validation_token":"[REDACTED]"', $raw);

    // 遮蔽之後仍要留下可追查的關聯標記，否則客服收到「啟用失敗」就無從定位
    $entry = $lastLogEntry->call($this, $this->logPath);
    $this->assertSame(
        substr(License::hashKey($licenseKey), 0, 12),
        $entry['request']['license_key_hash_prefix'] ?? null,
    );
});

test('redaction reaches nested payload keys', function () use ($lastLogEntry): void {
    // 不帶 Authorization → 401，屬 >= 400 會記完整 body；此處驗證的是遞迴遮蔽
    // 本身，故用一個有巢狀敏感鍵的 request body。
    $this->postJson('/api/licensing/v1/validate', [
        'license_key' => 'NESTED-SECRET-KEY',
        'fingerprint' => 'nested-fp',
        'data' => ['token' => 'should-not-appear'],
    ])->assertStatus(401);

    $entry = $lastLogEntry->call($this, $this->logPath);
    $this->assertSame('[REDACTED]', $entry['request']['license_key']);
    $this->assertSame('[REDACTED]', $entry['request']['data']['token']);
});
