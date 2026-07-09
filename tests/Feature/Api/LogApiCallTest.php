<?php

namespace Tests\Feature\Api;

use App\Models\License;
use App\Models\LicenseUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use LucaLongo\Licensing\Enums\LicenseStatus;
use LucaLongo\Licensing\Enums\UsageStatus;
use Tests\Concerns\MocksLicenseTokenVerifier;
use Tests\TestCase;

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
class LogApiCallTest extends TestCase
{
    use MocksLicenseTokenVerifier;
    use RefreshDatabase;

    private string $logPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logPath = storage_path('logs/testing-api-'.getmypid().'.log');
        config(['logging.channels.api' => [
            'driver' => 'single',
            'path' => $this->logPath,
            'level' => 'info',
        ]]);

        if (file_exists($this->logPath)) {
            unlink($this->logPath);
        }
    }

    protected function tearDown(): void
    {
        if (file_exists($this->logPath)) {
            unlink($this->logPath);
        }

        parent::tearDown();
    }

    /**
     * 讀出最後一筆 api log 的 JSON payload。
     *
     * @return array<string, mixed>
     */
    private function lastLogEntry(): array
    {
        $this->assertFileExists($this->logPath);

        $lines = array_values(array_filter(explode("\n", trim(file_get_contents($this->logPath)))));
        $lastLine = end($lines);

        // log line 格式為 "[timestamp] env.INFO: {json}"
        $decoded = json_decode(substr($lastLine, strpos($lastLine, '{')), true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    public function test_get_success_omits_response_body_and_masks_sensitive_headers(): void
    {
        $this->acceptAnyBearerToken();

        $this->getJsonWithToken('/api/v1/wordwalls')->assertOk();

        $entry = $this->lastLogEntry();
        $this->assertSame(200, $entry['status']);
        $this->assertNull($entry['response']);
        $this->assertArrayNotHasKey('authorization', $entry['headers']);
        $this->assertArrayNotHasKey('cookie', $entry['headers']);
        // 其餘 headers 仍要保留供除錯
        $this->assertArrayHasKey('host', $entry['headers']);
    }

    public function test_get_304_omits_response_body(): void
    {
        $this->acceptAnyBearerToken();

        $first = $this->getJsonWithToken('/api/v1/wordwalls');
        $etag = $first->headers->get('ETag');
        $this->assertNotNull($etag);

        $this->withHeaders([
            'Authorization' => 'Bearer stub-bearer-token',
            'If-None-Match' => $etag,
        ])->getJson('/api/v1/wordwalls')->assertStatus(304);

        $entry = $this->lastLogEntry();
        $this->assertSame(304, $entry['status']);
        $this->assertNull($entry['response']);
    }

    public function test_error_response_body_is_still_logged(): void
    {
        // 不帶 Authorization → VerifyBearerToken 回 401，屬 >= 400 應保留完整 body
        $this->getJson('/api/v1/wordwalls')->assertStatus(401);

        $entry = $this->lastLogEntry();
        $this->assertSame(401, $entry['status']);
        $this->assertSame('INVALID_TOKEN', $entry['response']['error']['code'] ?? null);
    }

    public function test_non_get_success_response_body_is_still_logged(): void
    {
        // validate 成功時會真實簽發驗證收據 token，需要 root/signing key
        config([
            'licensing.crypto.keystore.driver' => 'database',
            'licensing.crypto.keystore.passphrase' => 'log-api-call-test-passphrase',
        ]);
        Artisan::call('licensing:keys:make-root');
        Artisan::call('licensing:keys:issue-signing', ['--days' => 365]);

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

        $entry = $this->lastLogEntry();
        $this->assertSame(200, $entry['status']);
        $this->assertTrue($entry['response']['success'] ?? false);
    }
}
