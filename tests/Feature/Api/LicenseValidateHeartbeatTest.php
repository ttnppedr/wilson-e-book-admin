<?php

namespace Tests\Feature\Api;

use App\Models\License;
use App\Models\LicenseUsage;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use LucaLongo\Licensing\Enums\LicenseStatus;
use LucaLongo\Licensing\Enums\UsageStatus;
use Tests\Concerns\MocksLicenseTokenVerifier;
use Tests\TestCase;

/**
 * ValidateController 心跳路徑的 feature tests。
 *
 * 驗證 heartbeat 更新 last_seen_at、client data 合併進 meta.client_data，
 * 以及一般心跳（不帶 data）不需要額外的 refresh 也能回傳正確狀態。
 *
 * 覆寫位置：app/Http/Controllers/Api/ValidateController.php::validateLicense()
 */
class LicenseValidateHeartbeatTest extends TestCase
{
    use MocksLicenseTokenVerifier;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // validate 成功時會簽發驗證收據 token（真實走 WilsonPasetoTokenService），
        // 因此即使 bearer 驗證被 MocksLicenseTokenVerifier mock 掉，仍需要
        // 真實的 root/signing key 存在，否則 issueToken 會 500。
        config([
            'licensing.crypto.keystore.driver' => 'database',
            'licensing.crypto.keystore.passphrase' => 'heartbeat-test-passphrase',
        ]);
        Artisan::call('licensing:keys:make-root');
        Artisan::call('licensing:keys:issue-signing', ['--days' => 365]);
    }

    /**
     * 建立 active license + active usage，回傳 [license key, usage]。
     *
     * @return array{0: string, 1: LicenseUsage}
     */
    private function makeActiveLicenseWithUsage(string $fingerprint): array
    {
        $licenseKey = 'HEARTBEAT'.bin2hex(random_bytes(6));
        $license = License::create([
            'key_hash' => License::hashKey($licenseKey),
            'status' => LicenseStatus::Active,
            'activated_at' => now(),
            'expires_at' => now()->addDays(30),
            'max_usages' => 1,
            'meta' => [],
        ]);

        $usage = LicenseUsage::create([
            'license_id' => $license->getKey(),
            'usage_fingerprint' => $fingerprint,
            'status' => UsageStatus::Active,
            'registered_at' => now()->subDay(),
            'last_seen_at' => now()->subDay(),
            'client_type' => 'desktop',
            'meta' => [],
        ]);

        return [$licenseKey, $usage];
    }

    public function test_heartbeat_without_data_updates_last_seen_at(): void
    {
        [$licenseKey, $usage] = $this->makeActiveLicenseWithUsage('hb-fp');
        $this->acceptAnyTokenFor($licenseKey, 'hb-fp');

        $before = $usage->last_seen_at;

        $response = $this->postValidateWithToken([
            'license_key' => $licenseKey,
            'fingerprint' => 'hb-fp',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertTrue($usage->fresh()->last_seen_at->gt($before));
    }

    public function test_heartbeat_with_data_merges_into_client_data(): void
    {
        [$licenseKey, $usage] = $this->makeActiveLicenseWithUsage('hb-fp');
        $this->acceptAnyTokenFor($licenseKey, 'hb-fp');

        $this->postValidateWithToken([
            'license_key' => $licenseKey,
            'fingerprint' => 'hb-fp',
            'data' => ['app_version' => '1.2.3'],
        ])->assertOk();

        // 第二次帶不同 key，驗證是合併而非覆蓋
        $this->postValidateWithToken([
            'license_key' => $licenseKey,
            'fingerprint' => 'hb-fp',
            'data' => ['device_name' => 'iPad'],
        ])->assertOk();

        $clientData = $usage->fresh()->meta['client_data'] ?? [];
        $this->assertSame('1.2.3', $clientData['app_version'] ?? null);
        $this->assertSame('iPad', $clientData['device_name'] ?? null);
    }

    public function test_usage_summary_is_correct_and_counts_active_usages_once(): void
    {
        // max_usages = 1、一個 active usage → active_usages 1、available_seats 0
        [$licenseKey] = $this->makeActiveLicenseWithUsage('hb-fp');
        $this->acceptAnyTokenFor($licenseKey, 'hb-fp');

        $usageCountQueries = 0;
        DB::listen(function (QueryExecuted $query) use (&$usageCountQueries): void {
            if (str_contains($query->sql, 'count(*)') && str_contains($query->sql, 'license_usages')) {
                $usageCountQueries++;
            }
        });

        $response = $this->postValidateWithToken([
            'license_key' => $licenseKey,
            'fingerprint' => 'hb-fp',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.license.active_usages', 1);
        $response->assertJsonPath('data.license.available_seats', 0);

        // 迴歸防護 formatLicense() 覆寫:vendor 版對同一條件重複 COUNT 兩次
        // (active_usages 一次、getAvailableSeats() 內部一次),覆寫後只允許一次。
        $this->assertSame(1, $usageCountQueries);
    }
}
