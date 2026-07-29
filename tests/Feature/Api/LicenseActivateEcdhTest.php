<?php

/**
 * /api/licensing/v1/activate 的 ECDH content key wrapping feature tests。
 *
 * 範圍：HTTP layer 的 validation 與 error path 驗證。
 *
 * 不涵蓋 happy path 的完整 PASETO 簽章 / unwrap 流程——因為 happy path 需要真實的
 * signing key 基礎設施（寫死路徑在 storage/app/licensing/keys，避免污染 prod）。
 * Happy path 的 wrap 邏輯已經在 `ContentKeyWrapperTest::test_wrap_is_interoperable_with_fixture_vectors`
 * 以 byte-perfect fixture 驗證過；端對端驗證則在 Task #10 的手動 E2E 測試進行。
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use LucaLongo\Licensing\Enums\LicenseStatus;
use LucaLongo\Licensing\Models\License;

uses(RefreshDatabase::class);

$activateUrl = '/api/licensing/v1/activate';

/**
 * Mock `api` log channel 並期望收到一次 `error` 呼叫，context 中的
 * `internal_code` 等於指定值。
 *
 * 使用情境：所有 API 5xx response 都已被遮蔽成 generic `SERVER_ERROR`，
 * 測試無法從 HTTP response 區分具體的內部錯誤原因，因此改用 log
 * assertion 驗證 `serverError()` helper 確實記錄了正確的 internal_code。
 *
 * 同時接受 `info` 呼叫（LogApiCall middleware 會在 response 後寫 info），
 * 避免干擾測試。
 */
$expectServerErrorLogged = function (string $expectedInternalCode): void {
    $apiChannel = Mockery::mock();
    $apiChannel->shouldReceive('info')->zeroOrMoreTimes();
    $apiChannel->shouldReceive('error')
        ->once()
        ->withArgs(function ($message, $context) use ($expectedInternalCode) {
            return ($context['internal_code'] ?? null) === $expectedInternalCode;
        });

    Log::shouldReceive('channel')
        ->with('api')
        ->zeroOrMoreTimes()
        ->andReturn($apiChannel);
};

test('activate rejects when client ephemeral public key is missing', function () use ($activateUrl): void {
    $response = $this->postJson($activateUrl, [
        'license_key' => 'ANY-KEY',
        'fingerprint' => 'test-fp',
    ]);

    // 專案自訂的 validation error 格式：
    // { success: false, error: { code: 'VALIDATION_FAILED', details: {field: [...]} } }
    $response->assertStatus(422);
    $response->assertJsonPath('error.code', 'VALIDATION_FAILED');
    $response->assertJsonStructure([
        'error' => [
            'details' => ['client_ephemeral_public_key'],
        ],
    ]);
});

test('activate rejects when ephemeral public key is not base64', function () use ($activateUrl): void {
    $response = $this->postJson($activateUrl, [
        'license_key' => 'ANY-KEY',
        'fingerprint' => 'test-fp',
        'client_ephemeral_public_key' => '!!!not-valid-base64!!!',
    ]);

    $response->assertStatus(400);
    $response->assertJsonPath('error.code', 'INVALID_EPHEMERAL_KEY');
});

test('activate rejects when ephemeral public key has wrong length', function () use ($activateUrl): void {
    // 合法 base64，但解碼後只有 16 bytes（X25519 要求 32 bytes）
    $response = $this->postJson($activateUrl, [
        'license_key' => 'ANY-KEY',
        'fingerprint' => 'test-fp',
        'client_ephemeral_public_key' => base64_encode(random_bytes(16)),
    ]);

    $response->assertStatus(400);
    $response->assertJsonPath('error.code', 'INVALID_EPHEMERAL_KEY');
});

test('activate rejects unknown license key', function () use ($activateUrl): void {
    $response = $this->postJson($activateUrl, [
        'license_key' => 'NONEXISTENT-LICENSE-KEY-XYZ',
        'fingerprint' => 'test-fp',
        'client_ephemeral_public_key' => freshClientPublicKeyB64(),
    ]);

    $response->assertStatus(404);
    $response->assertJsonPath('error.code', 'INVALID_KEY');
});

test('activate rejects license without content key', function () use ($activateUrl, $expectServerErrorLogged): void {
    // Response 已被遮蔽成 generic SERVER_ERROR，改用 log assertion 驗證 internal_code
    $expectServerErrorLogged('MISSING_CONTENT_KEY');

    // 建立一個 active license，但 meta 裡沒有 content_key
    $licenseKey = 'TESTKEY'.bin2hex(random_bytes(6));
    License::create([
        'key_hash' => License::hashKey($licenseKey),
        'status' => LicenseStatus::Active,
        'activated_at' => now(),
        'expires_at' => now()->addDays(30),
        'max_usages' => 1,
        'meta' => [],  // 故意不設 content_key
    ]);

    $response = $this->postJson($activateUrl, [
        'license_key' => $licenseKey,
        'fingerprint' => 'test-fp',
        'client_ephemeral_public_key' => freshClientPublicKeyB64(),
    ]);

    $response->assertStatus(500);
    $response->assertJsonPath('error.code', 'SERVER_ERROR');
    $response->assertJsonPath('error.message', 'Server error');
});

test('activate rejects license with malformed content key', function () use ($activateUrl, $expectServerErrorLogged): void {
    $expectServerErrorLogged('MISSING_CONTENT_KEY');

    // meta['content_key'] 存在但不是 32 bytes base64
    $licenseKey = 'TESTKEY'.bin2hex(random_bytes(6));
    License::create([
        'key_hash' => License::hashKey($licenseKey),
        'status' => LicenseStatus::Active,
        'activated_at' => now(),
        'expires_at' => now()->addDays(30),
        'max_usages' => 1,
        'meta' => ['content_key' => base64_encode(random_bytes(16))],  // 只有 16 bytes
    ]);

    $response = $this->postJson($activateUrl, [
        'license_key' => $licenseKey,
        'fingerprint' => 'test-fp',
        'client_ephemeral_public_key' => freshClientPublicKeyB64(),
    ]);

    $response->assertStatus(500);
    $response->assertJsonPath('error.code', 'SERVER_ERROR');
    $response->assertJsonPath('error.message', 'Server error');
});

/**
 * 迴歸防護：永久授權 (expires_at = null) 不應被前置檢查攔截。
 *
 * 舊行為會在前置檢查階段攔下 null expires_at 的 license。修正後前置檢查
 * 只保留 offline token 啟用判斷，null expires_at 可以穿過，本測試讓請求
 * 穿過前置檢查後在下一關 (沒有 scope → 拿不到 content key) 停下。
 *
 * 斷言方式：
 *   - 從 HTTP response 無法區分（所有 500 已被遮蔽成 generic SERVER_ERROR）
 *   - 改從 `api` log channel 驗證 `internal_code === 'MISSING_CONTENT_KEY'`，
 *     證明請求確實穿過 TOKEN_REQUIRED 前置檢查進到 extractRawContentKey 階段
 */
test('activate does not reject license with null expires at', function () use ($activateUrl, $expectServerErrorLogged): void {
    $expectServerErrorLogged('MISSING_CONTENT_KEY');

    $licenseKey = 'TESTKEY'.bin2hex(random_bytes(6));
    License::create([
        'key_hash' => License::hashKey($licenseKey),
        'status' => LicenseStatus::Active,
        'activated_at' => now(),
        'expires_at' => null,  // 永久授權：關鍵變更點
        'max_usages' => 1,
        'meta' => [],
    ]);

    $response = $this->postJson($activateUrl, [
        'license_key' => $licenseKey,
        'fingerprint' => 'test-fp',
        'client_ephemeral_public_key' => freshClientPublicKeyB64(),
    ]);

    $response->assertStatus(500);
    $response->assertJsonPath('error.code', 'SERVER_ERROR');
    $response->assertJsonPath('error.message', 'Server error');
});

/**
 * activate 的 metadata 收斂。
 *
 * 白名單鍵最終會寫進 `license_usages.meta`（JSON 欄位），而 LicenseUsage 是
 * Auditable——每次寫入在 audits 再存一份，約 2 倍放大。ValidateController 早已為
 * 完全相同的威脅加了鍵名白名單 + 鍵數 + 值長度限制，activate 這條同樣寫 usage.meta
 * 的路徑先前完全沒有，防線只做了一半。
 *
 * validation 發生在 license 查詢之前，故以不存在的授權碼即可測到。
 */
$activatePayload = function (array $metadata): array {
    return [
        'license_key' => 'ANY-KEY',
        'fingerprint' => 'meta-fp',
        'client_ephemeral_public_key' => base64_encode(random_bytes(32)),
        'metadata' => $metadata,
    ];
};

test('activate rejects oversized metadata values', function () use ($activateUrl, $activatePayload): void {
    $response = $this->postJson($activateUrl, $activatePayload([
        'client_type' => str_repeat('a', 256),
    ]));

    $response->assertStatus(422);
    $response->assertJsonPath('error.code', 'VALIDATION_FAILED');
    $response->assertJsonStructure(['error' => ['details' => ['metadata.client_type']]]);
});

test('activate rejects unbounded metadata meta payloads', function () use ($activateUrl, $activatePayload): void {
    // 未受限時，持金鑰者可用 php.ini 上限內的大 JSON 撐爆單列（並被 audit 放大）
    $response = $this->postJson($activateUrl, $activatePayload([
        'meta' => ['blob' => str_repeat('x', 5000)],
    ]));

    $response->assertStatus(422);
    $response->assertJsonStructure(['error' => ['details' => ['metadata.meta.blob']]]);
});

test('activate rejects too many metadata meta keys', function () use ($activateUrl, $activatePayload): void {
    $response = $this->postJson($activateUrl, $activatePayload([
        'meta' => array_combine(
            array_map(fn (int $i): string => "k{$i}", range(1, 11)),
            array_fill(0, 11, 'v'),
        ),
    ]));

    $response->assertStatus(422);
    $response->assertJsonStructure(['error' => ['details' => ['metadata.meta']]]);
});

test('activate accepts metadata within the allowed shape', function () use ($activateUrl, $activatePayload): void {
    // 通過 validation 後才會去查授權碼，未知授權碼回 404 即代表沒被 422 擋下
    $response = $this->postJson($activateUrl, $activatePayload([
        'client_type' => 'desktop',
        'name' => 'Wilson App',
        'meta' => ['os_version' => '15.5'],
    ]));

    $response->assertStatus(404);
    $response->assertJsonPath('error.code', 'INVALID_KEY');
});
