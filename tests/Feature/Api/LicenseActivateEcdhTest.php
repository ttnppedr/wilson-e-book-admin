<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use LucaLongo\Licensing\Enums\LicenseStatus;
use LucaLongo\Licensing\Models\License;
use Mockery;
use Tests\TestCase;

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
class LicenseActivateEcdhTest extends TestCase
{
    use RefreshDatabase;

    private const ACTIVATE_URL = '/api/licensing/v1/activate';

    /**
     * 產生一組合法的 X25519 client ephemeral public key（base64 字串）。
     */
    private function freshClientPublicKeyB64(): string
    {
        $kp = sodium_crypto_box_keypair();

        return base64_encode(sodium_crypto_box_publickey($kp));
    }

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
    private function expectServerErrorLogged(string $expectedInternalCode): void
    {
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
    }

    public function test_activate_rejects_when_client_ephemeral_public_key_is_missing(): void
    {
        $response = $this->postJson(self::ACTIVATE_URL, [
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
    }

    public function test_activate_rejects_when_ephemeral_public_key_is_not_base64(): void
    {
        $response = $this->postJson(self::ACTIVATE_URL, [
            'license_key' => 'ANY-KEY',
            'fingerprint' => 'test-fp',
            'client_ephemeral_public_key' => '!!!not-valid-base64!!!',
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('error.code', 'INVALID_EPHEMERAL_KEY');
    }

    public function test_activate_rejects_when_ephemeral_public_key_has_wrong_length(): void
    {
        // 合法 base64，但解碼後只有 16 bytes（X25519 要求 32 bytes）
        $response = $this->postJson(self::ACTIVATE_URL, [
            'license_key' => 'ANY-KEY',
            'fingerprint' => 'test-fp',
            'client_ephemeral_public_key' => base64_encode(random_bytes(16)),
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('error.code', 'INVALID_EPHEMERAL_KEY');
    }

    public function test_activate_rejects_unknown_license_key(): void
    {
        $response = $this->postJson(self::ACTIVATE_URL, [
            'license_key' => 'NONEXISTENT-LICENSE-KEY-XYZ',
            'fingerprint' => 'test-fp',
            'client_ephemeral_public_key' => $this->freshClientPublicKeyB64(),
        ]);

        $response->assertStatus(404);
        $response->assertJsonPath('error.code', 'INVALID_KEY');
    }

    public function test_activate_rejects_license_without_content_key(): void
    {
        // Response 已被遮蔽成 generic SERVER_ERROR，改用 log assertion 驗證 internal_code
        $this->expectServerErrorLogged('MISSING_CONTENT_KEY');

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

        $response = $this->postJson(self::ACTIVATE_URL, [
            'license_key' => $licenseKey,
            'fingerprint' => 'test-fp',
            'client_ephemeral_public_key' => $this->freshClientPublicKeyB64(),
        ]);

        $response->assertStatus(500);
        $response->assertJsonPath('error.code', 'SERVER_ERROR');
        $response->assertJsonPath('error.message', 'Server error');
    }

    public function test_activate_rejects_license_with_malformed_content_key(): void
    {
        $this->expectServerErrorLogged('MISSING_CONTENT_KEY');

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

        $response = $this->postJson(self::ACTIVATE_URL, [
            'license_key' => $licenseKey,
            'fingerprint' => 'test-fp',
            'client_ephemeral_public_key' => $this->freshClientPublicKeyB64(),
        ]);

        $response->assertStatus(500);
        $response->assertJsonPath('error.code', 'SERVER_ERROR');
        $response->assertJsonPath('error.message', 'Server error');
    }

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
    public function test_activate_does_not_reject_license_with_null_expires_at(): void
    {
        $this->expectServerErrorLogged('MISSING_CONTENT_KEY');

        $licenseKey = 'TESTKEY'.bin2hex(random_bytes(6));
        License::create([
            'key_hash' => License::hashKey($licenseKey),
            'status' => LicenseStatus::Active,
            'activated_at' => now(),
            'expires_at' => null,  // 永久授權：關鍵變更點
            'max_usages' => 1,
            'meta' => [],
        ]);

        $response = $this->postJson(self::ACTIVATE_URL, [
            'license_key' => $licenseKey,
            'fingerprint' => 'test-fp',
            'client_ephemeral_public_key' => $this->freshClientPublicKeyB64(),
        ]);

        $response->assertStatus(500);
        $response->assertJsonPath('error.code', 'SERVER_ERROR');
        $response->assertJsonPath('error.message', 'Server error');
    }
}
