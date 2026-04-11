<?php

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use LucaLongo\Licensing\Enums\LicenseStatus;
use LucaLongo\Licensing\Models\License;
use Tests\TestCase;

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
class LicenseGuardStateTest extends TestCase
{
    use RefreshDatabase;

    private const ACTIVATE_URL = '/api/licensing/v1/activate';

    private const VALIDATE_URL = '/api/licensing/v1/validate';

    /**
     * 產生一組合法的 X25519 client ephemeral public key（base64 字串）。
     */
    private function freshClientPublicKeyB64(): string
    {
        $kp = sodium_crypto_box_keypair();

        return base64_encode(sodium_crypto_box_publickey($kp));
    }

    /**
     * 建立一個指定狀態的 license row（不透過 activate 流程）。
     */
    private function makeLicense(LicenseStatus $status, ?\DateTimeInterface $expiresAt = null): string
    {
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
    }

    // -------------------------------------------------------------------------
    // validate endpoint
    // -------------------------------------------------------------------------

    public function test_validate_returns_cancelled_license_for_cancelled_status(): void
    {
        $licenseKey = $this->makeLicense(LicenseStatus::Cancelled);

        $response = $this->postJson(self::VALIDATE_URL, [
            'license_key' => $licenseKey,
            'fingerprint' => 'test-fp',
        ]);

        $response->assertStatus(410);
        $response->assertJsonPath('error.code', 'CANCELLED_LICENSE');
    }

    public function test_validate_returns_suspended_license_for_suspended_status(): void
    {
        $licenseKey = $this->makeLicense(LicenseStatus::Suspended);

        $response = $this->postJson(self::VALIDATE_URL, [
            'license_key' => $licenseKey,
            'fingerprint' => 'test-fp',
        ]);

        $response->assertStatus(423);
        $response->assertJsonPath('error.code', 'SUSPENDED_LICENSE');
    }

    public function test_validate_returns_expired_license_for_expired_status(): void
    {
        $licenseKey = $this->makeLicense(LicenseStatus::Expired, now()->subDays(10));

        $response = $this->postJson(self::VALIDATE_URL, [
            'license_key' => $licenseKey,
            'fingerprint' => 'test-fp',
        ]);

        $response->assertStatus(410);
        $response->assertJsonPath('error.code', 'EXPIRED_LICENSE');
    }

    // -------------------------------------------------------------------------
    // activate endpoint
    // -------------------------------------------------------------------------

    public function test_activate_returns_cancelled_license_for_cancelled_status(): void
    {
        $licenseKey = $this->makeLicense(LicenseStatus::Cancelled);

        $response = $this->postJson(self::ACTIVATE_URL, [
            'license_key' => $licenseKey,
            'fingerprint' => 'test-fp',
            'client_ephemeral_public_key' => $this->freshClientPublicKeyB64(),
        ]);

        $response->assertStatus(410);
        $response->assertJsonPath('error.code', 'CANCELLED_LICENSE');
    }

    public function test_activate_returns_suspended_license_for_suspended_status(): void
    {
        $licenseKey = $this->makeLicense(LicenseStatus::Suspended);

        $response = $this->postJson(self::ACTIVATE_URL, [
            'license_key' => $licenseKey,
            'fingerprint' => 'test-fp',
            'client_ephemeral_public_key' => $this->freshClientPublicKeyB64(),
        ]);

        $response->assertStatus(423);
        $response->assertJsonPath('error.code', 'SUSPENDED_LICENSE');
    }

    // -------------------------------------------------------------------------
    // 優先順序驗證：cancelled > suspended > expired
    // -------------------------------------------------------------------------

    public function test_cancelled_takes_precedence_over_expired_check(): void
    {
        // 即使 expires_at 已經過去，cancelled 狀態的 license 仍應回報 CANCELLED_LICENSE
        // 而不是 EXPIRED_LICENSE，因為 cancelled 是更強的終態
        $licenseKey = $this->makeLicense(LicenseStatus::Cancelled, now()->subDays(10));

        $response = $this->postJson(self::VALIDATE_URL, [
            'license_key' => $licenseKey,
            'fingerprint' => 'test-fp',
        ]);

        $response->assertStatus(410);
        $response->assertJsonPath('error.code', 'CANCELLED_LICENSE');
    }

    public function test_suspended_takes_precedence_over_expired_check(): void
    {
        $licenseKey = $this->makeLicense(LicenseStatus::Suspended, now()->subDays(10));

        $response = $this->postJson(self::VALIDATE_URL, [
            'license_key' => $licenseKey,
            'fingerprint' => 'test-fp',
        ]);

        $response->assertStatus(423);
        $response->assertJsonPath('error.code', 'SUSPENDED_LICENSE');
    }
}
