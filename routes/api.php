<?php

use App\Http\Controllers\Api\LicenseController;
use App\Http\Controllers\Api\ValidateController;
use App\Http\Controllers\Api\WordwallCategoryController;
use App\Http\Controllers\Api\WordwallCategoryImageController;
use App\Http\Controllers\Api\WordwallController;
use App\Http\Middleware\SetResponseEtag;
use App\Http\Middleware\VerifyBearerToken;
use Illuminate\Support\Facades\Route;

// Vendor 的 api.enabled 已關閉，所有路由在此明確註冊。
// activate、validate 使用自訂 Controller，分別處理 ECDH content key wrapping 與 heartbeat 合併。
//
// 保護層級:
//   - activate:rate limit（IP + fingerprint），token 由此端點簽發所以無從要求預先提供
//   - validate:rate limit + VerifyBearerToken（要求回帶 activate 的 PASETO token）
Route::prefix('licensing/v1')
    ->group(function (): void {
        Route::post('activate', [LicenseController::class, 'activate'])
            ->middleware('throttle:licensing-activate')
            ->name('licensing.activate');

        Route::post('validate', [ValidateController::class, 'validateLicense'])
            ->middleware(['throttle:licensing-validate', VerifyBearerToken::class])
            ->name('licensing.validate');
    });

Route::prefix('v1')
    ->group(function (): void {
        Route::get('wordwalls', WordwallController::class)
            ->middleware([
                'throttle:api-wordwall',
                VerifyBearerToken::class,
                SetResponseEtag::class,
            ])
            ->name('v1.wordwalls');

        Route::get('wordwall-categories', WordwallCategoryController::class)
            ->middleware([
                'throttle:api-wordwall',
                VerifyBearerToken::class,
                SetResponseEtag::class,
            ])
            ->name('v1.wordwall-categories');

        // 分類圖片：物件維持 private，此端點即時簽發 S3 presigned 短效網址並 302 轉址。
        // 不掛 SetResponseEtag（回應為轉址非可快取 body）；throttle 具名 limiter 以 path
        // 為 key，故每個分類圖各自獨立計數、不與清單端點互相排擠。
        Route::get('wordwall-categories/{wordwallCategory}/image', WordwallCategoryImageController::class)
            ->middleware([
                'throttle:api-wordwall',
                VerifyBearerToken::class,
            ])
            ->name('v1.wordwall-categories.image');
    });
