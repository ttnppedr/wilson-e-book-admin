<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use LucaLongo\Licensing\Contracts\TokenVerifier;
use LucaLongo\Licensing\Enums\LicenseStatus;
use LucaLongo\Licensing\Models\License;
use Mockery;
use RuntimeException;
use Tests\Concerns\MocksLicenseTokenVerifier;
use Tests\TestCase;

/**
 * /api/licensing/v1/validate 的 VerifyLicenseToken middleware feature 測試。
 *
 * 範圍：路由層面驗證 — 確認 middleware 在整合環境下能正確讀 Authorization header、
 * 從容器拿到 TokenVerifier、處理各種失敗路徑。真實 PASETO 簽章 / Ed25519 verify
 * 路徑由 vendor 套件自己的測試涵蓋，本測試不依賴 signing key 基礎設施。
 */
class LicenseValidateTokenTest extends TestCase
{
    use MocksLicenseTokenVerifier;
    use RefreshDatabase;

    private const VALIDATE_URL = '/api/licensing/v1/validate';

    /**
     * 建立一個 active 狀態的 license row，回傳 license key。
     */
    private function makeActiveLicense(): string
    {
        $licenseKey = 'VALIDATE'.bin2hex(random_bytes(6));
        License::create([
            'key_hash' => License::hashKey($licenseKey),
            'status' => LicenseStatus::Active,
            'activated_at' => now(),
            'expires_at' => now()->addDays(30),
            'max_usages' => 1,
            'meta' => [],
        ]);

        return $licenseKey;
    }

    public function test_rejects_validate_without_authorization_header(): void
    {
        $licenseKey = $this->makeActiveLicense();

        // 刻意不掛 TokenVerifier mock，因為 middleware 在讀取 header 時就會 401
        $response = $this->postJson(self::VALIDATE_URL, [
            'license_key' => $licenseKey,
            'fingerprint' => 'test-fp',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'INVALID_TOKEN');
    }

    public function test_rejects_validate_when_token_verifier_throws(): void
    {
        $licenseKey = $this->makeActiveLicense();

        // 裝一個會炸的 verifier，模擬簽章錯誤、過期、kid 查無等情境
        $verifier = Mockery::mock(TokenVerifier::class);
        $verifier->shouldReceive('verify')
            ->once()
            ->andThrow(new RuntimeException('paseto parse failed'));
        $this->app->instance(TokenVerifier::class, $verifier);

        $response = $this->withHeaders(['Authorization' => 'Bearer anything'])
            ->postJson(self::VALIDATE_URL, [
                'license_key' => $licenseKey,
                'fingerprint' => 'test-fp',
            ]);

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'INVALID_TOKEN');
    }

    public function test_rejects_validate_when_token_belongs_to_different_license(): void
    {
        $targetLicenseKey = $this->makeActiveLicense();
        $otherLicenseKey = $this->makeActiveLicense();

        // 拿「另一張 license」的 token 去打目標 license，middleware 應擋下
        $this->acceptAnyTokenFor($otherLicenseKey, 'test-fp');

        $response = $this->postValidateWithToken([
            'license_key' => $targetLicenseKey,
            'fingerprint' => 'test-fp',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'INVALID_TOKEN');
    }

    public function test_rejects_validate_when_token_fingerprint_does_not_match(): void
    {
        $licenseKey = $this->makeActiveLicense();
        // token 綁 device-A，但 request 帶 device-B
        $this->acceptAnyTokenFor($licenseKey, 'device-A');

        $response = $this->postValidateWithToken([
            'license_key' => $licenseKey,
            'fingerprint' => 'device-B',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'INVALID_TOKEN');
    }

    public function test_accepts_validate_when_token_matches_license_and_fingerprint(): void
    {
        $licenseKey = $this->makeActiveLicense();
        $this->acceptAnyTokenFor($licenseKey, 'test-fp');

        // 這個請求會穿過 middleware 進到 controller。由於測試沒有建立 LicenseUsage，
        // controller 會在 usageRegistrar->findByFingerprint() 找不到 usage，
        // 回 403 FINGERPRINT_MISMATCH。這證明 middleware 放行了請求。
        $response = $this->postValidateWithToken([
            'license_key' => $licenseKey,
            'fingerprint' => 'test-fp',
        ]);

        $response->assertStatus(403);
        $response->assertJsonPath('error.code', 'FINGERPRINT_MISMATCH');
    }
}
