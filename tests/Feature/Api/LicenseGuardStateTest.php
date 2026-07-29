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

use App\Models\License;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LucaLongo\Licensing\Enums\LicenseStatus;
use OwenIt\Auditing\Models\Audit;
use Tests\Concerns\MocksLicenseTokenVerifier;

uses(MocksLicenseTokenVerifier::class, RefreshDatabase::class);

$activateUrl = '/api/licensing/v1/activate';

/*
 * 把 api channel 導向測試專用檔案（手法與 LogApiCallTest 一致）。
 *
 * 不用 Log::spy()：本專案的寫法是 `Log::channel('api')->info()`，spy 對未設定的
 * channel() 回 null，鏈式呼叫當場炸開；而 spy 攔的是 facade 本身，也接不到
 * channel 物件上的 info()。直接讀檔斷言原文最貼近實際落地的內容。
 */
beforeEach(function (): void {
    $this->logPath = storage_path('logs/testing-guard-'.getmypid().'.log');
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

/** 由授權碼取回 model，用來斷言 API 是否寫回了狀態。 */
$findLicense = fn (string $licenseKey): License => License::query()
    ->where('key_hash', License::hashKey($licenseKey))
    ->firstOrFail();

/** 取該授權最後一筆 status 變更的 audit。 */
$latestStatusAudit = fn (License $license): ?Audit => Audit::query()
    ->where('auditable_type', $license->getMorphClass())
    ->where('auditable_id', $license->getKey())
    ->where('event', 'updated')
    ->latest('id')
    ->first();

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
// 到期即時收斂：API 發現過期時把 status 寫回 DB，不必等隔天 02:00 的排程
// -------------------------------------------------------------------------

test('validate writes the expired status back and leaves an audit trail', function () use ($makeLicense, $findLicense, $latestStatusAudit): void {
    // status 還停在 active（排程尚未跑過），但 expires_at 已過。
    $licenseKey = $makeLicense(LicenseStatus::Active, now()->subMinute());
    $this->acceptAnyTokenFor($licenseKey, 'test-fp');

    $this->postValidateWithToken([
        'license_key' => $licenseKey,
        'fingerprint' => 'test-fp',
    ])->assertStatus(410);

    $license = $findLicense($licenseKey);
    $this->assertSame(LicenseStatus::Expired, $license->status);

    $audit = $latestStatusAudit($license);
    $this->assertNotNull($audit, '狀態變更必須留下 audit');
    $this->assertSame(LicenseStatus::Active->value, $audit->old_values['status']);
    $this->assertSame(LicenseStatus::Expired->value, $audit->new_values['status']);
});

/**
 * 「這次收斂由 API 觸發」必須留下可追查的訊號。
 *
 * 刻意不靠 audit 的 url 欄位：owen-it 的 UrlResolver 只認
 * `App::runningInConsole()`，而 Pest 由 CLI 啟動，所有 feature test 的 audit
 * url 都是 'console'——正式環境雖然分得出來，卻沒有任何測試守得住它。
 */
test('validate logs when it auto-expires a license', function () use ($makeLicense, $findLicense): void {
    $licenseKey = $makeLicense(LicenseStatus::Active, now()->subMinute());
    $this->acceptAnyTokenFor($licenseKey, 'test-fp');

    $this->postValidateWithToken([
        'license_key' => $licenseKey,
        'fingerprint' => 'test-fp',
    ])->assertStatus(410);

    $licenseId = $findLicense($licenseKey)->getKey();
    $log = file_get_contents($this->logPath);

    $this->assertStringContainsString('[api] license auto-expired', $log);
    $this->assertStringContainsString('"license_id":'.$licenseId, $log);
});

/**
 * 已經是 expired 的授權再打一次，不得重複記錄——log 只在狀態真的改變時寫，
 * 否則一台不斷重試的過期裝置會把 api log 洗版。
 */
test('validate does not log again for an already expired license', function () use ($makeLicense): void {
    $licenseKey = $makeLicense(LicenseStatus::Expired, now()->subDays(10));
    $this->acceptAnyTokenFor($licenseKey, 'test-fp');

    $this->postValidateWithToken([
        'license_key' => $licenseKey,
        'fingerprint' => 'test-fp',
    ])->assertStatus(410);

    $log = file_exists($this->logPath) ? file_get_contents($this->logPath) : '';

    $this->assertStringNotContainsString('[api] license auto-expired', $log);
});

test('activate writes the expired status back and leaves an audit trail', function () use ($makeLicense, $findLicense, $latestStatusAudit, $activateUrl): void {
    $licenseKey = $makeLicense(LicenseStatus::Active, now()->subMinute());

    $this->postJson($activateUrl, [
        'license_key' => $licenseKey,
        'fingerprint' => 'test-fp',
        'client_ephemeral_public_key' => freshClientPublicKeyB64(),
    ])->assertStatus(410);

    $license = $findLicense($licenseKey);
    $this->assertSame(LicenseStatus::Expired, $license->status);

    $audit = $latestStatusAudit($license);
    $this->assertNotNull($audit);
    $this->assertSame(LicenseStatus::Expired->value, $audit->new_values['status']);
});

/**
 * 重複請求不得重複寫 audit——transitionToExpired() 的兩道 guard 讓它冪等，
 * 否則一台不斷重試的過期裝置會把 audits 表灌爆。
 */
test('repeated requests on an expired license write the audit only once', function () use ($makeLicense, $findLicense): void {
    $licenseKey = $makeLicense(LicenseStatus::Active, now()->subMinute());
    $this->acceptAnyTokenFor($licenseKey, 'test-fp');

    foreach (range(1, 3) as $ignored) {
        $this->postValidateWithToken([
            'license_key' => $licenseKey,
            'fingerprint' => 'test-fp',
        ])->assertStatus(410);
    }

    $license = $findLicense($licenseKey);
    $auditCount = Audit::query()
        ->where('auditable_type', $license->getMorphClass())
        ->where('auditable_id', $license->getKey())
        ->where('event', 'updated')
        ->count();

    $this->assertSame(1, $auditCount);
});

/**
 * 建立後從未啟用、放到過期才第一次連線的授權：**不得**被寫成 active。
 *
 * vendor 的 canActivate() 只看 status === Pending、activate() 也不看 expires_at，
 * 少了 activateIfPending() 這道檢查，DB 會留下「今天啟用過」的假 activated_at
 * 與多餘的一筆 audit，但實際上從沒有任何裝置啟用過它。
 */
test('pending license past its expiry is never activated', function () use ($makeLicense, $findLicense): void {
    $licenseKey = $makeLicense(LicenseStatus::Pending, now()->subDay());
    $this->acceptAnyTokenFor($licenseKey, 'test-fp');

    $response = $this->postValidateWithToken([
        'license_key' => $licenseKey,
        'fingerprint' => 'test-fp',
    ]);

    $response->assertStatus(410);
    $response->assertJsonPath('error.code', 'EXPIRED_LICENSE');

    $license = $findLicense($licenseKey);
    // 一步收斂成 expired，activated_at 維持 null（忠實反映「從未啟用」）。
    $this->assertSame(LicenseStatus::Expired, $license->status);
    $this->assertNull($license->activated_at);

    // 只有 pending → expired 一筆，不該有 pending → active 的中繼紀錄。
    $audits = Audit::query()
        ->where('auditable_type', $license->getMorphClass())
        ->where('auditable_id', $license->getKey())
        ->where('event', 'updated')
        ->get();

    $this->assertCount(1, $audits);
    $this->assertSame(LicenseStatus::Pending->value, $audits->first()->old_values['status']);
    $this->assertSame(LicenseStatus::Expired->value, $audits->first()->new_values['status']);
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
