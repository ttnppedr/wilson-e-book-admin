<?php

/**
 * 守護「API 路徑拿到的 License 是 App\Models\License」這件事。
 *
 * 為什麼需要專門的測試：vendor 的 `Licensing::findByKey()`
 * （src/Licensing.php:19-22）**硬呼叫** `LucaLongo\Licensing\Models\License::findByKey()`，
 * 不讀 `config('licensing.models.license')`——即使 vendor 其他地方
 * （`LicenseUsage::license()`、`CheckExpirationsCommand`）都有讀。少了
 * `LicenseController::findLicense()` 的覆寫，activate / validate 兩個端點會拿到
 * vendor model，於是 `App\Models\License` 上的每一項覆寫都靜默失效：
 *
 *   - `implements Auditable` → API 對 License 的寫入完全不留 audit
 *   - `EnforcesAuditEvents` → quiet API 的 runtime 保護不存在
 *   - `transitionToExpired()` → 落回 vendor 版（要求先進 Grace），永遠 no-op
 *
 * 這種失效不會拋錯、不會有任何徵兆，只會讓稽核紀錄悄悄少掉一整條路徑，
 * 所以必須由測試釘住。升級 vendor 後若把覆寫拿掉，這裡會立刻變紅。
 */

use App\Http\Controllers\Api\LicenseController;
use App\Models\License;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LucaLongo\Licensing\Enums\LicenseStatus;
use OwenIt\Auditing\Models\Audit;
use Tests\Concerns\MocksLicenseTokenVerifier;

uses(MocksLicenseTokenVerifier::class, RefreshDatabase::class);

/** 建一張指定狀態的授權，回傳 [明文授權碼, model]。 */
$makeLicense = function (LicenseStatus $status, ?DateTimeInterface $expiresAt = null): array {
    $licenseKey = 'BINDING'.bin2hex(random_bytes(6));
    $license = License::create([
        'key_hash' => License::hashKey($licenseKey),
        'status' => $status,
        'activated_at' => $status === LicenseStatus::Pending ? null : now(),
        'expires_at' => $expiresAt ?? now()->addDays(30),
        'max_usages' => 1,
        'meta' => [],
    ]);

    return [$licenseKey, $license];
};

test('findLicense returns the app model, not the vendor one', function () use ($makeLicense): void {
    [$licenseKey] = $makeLicense(LicenseStatus::Active);

    $controller = app(LicenseController::class);
    $found = (new ReflectionMethod($controller, 'findLicense'))->invoke($controller, $licenseKey);

    $this->assertInstanceOf(License::class, $found);
});

/**
 * 行為面的守護：比型別斷言更貼近真正在乎的結果。
 *
 * 走 validate 端點觸發 pending → active。fingerprint 刻意不存在，讓請求在
 * `activateIfPending()` 之後、簽發 token 之前就以 403 收尾——狀態變更早已發生，
 * audit 該有就得有。
 */
test('a status change made through the api leaves an audit trail', function () use ($makeLicense): void {
    [$licenseKey, $license] = $makeLicense(LicenseStatus::Pending);
    $this->acceptAnyTokenFor($licenseKey, 'unregistered-fp');

    $this->postValidateWithToken([
        'license_key' => $licenseKey,
        'fingerprint' => 'unregistered-fp',
    ])->assertStatus(403);

    $this->assertSame(LicenseStatus::Active, $license->refresh()->status);

    $audit = Audit::query()
        ->where('auditable_type', $license->getMorphClass())
        ->where('auditable_id', $license->getKey())
        ->where('event', 'updated')
        ->latest('id')
        ->first();

    $this->assertNotNull($audit, 'API 觸發的 pending → active 必須留下 audit');
    $this->assertSame(LicenseStatus::Pending->value, $audit->old_values['status']);
    $this->assertSame(LicenseStatus::Active->value, $audit->new_values['status']);
});
