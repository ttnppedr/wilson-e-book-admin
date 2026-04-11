<?php

use App\Http\Controllers\Api\LicenseController;
use App\Http\Controllers\Api\ValidateController;
use App\Http\Middleware\VerifyLicenseToken;
use Illuminate\Support\Facades\Route;

// Vendor 的 api.enabled 已關閉，所有路由在此明確註冊。
// activate、validate 使用自訂 Controller，分別處理 ECDH content key wrapping 與 heartbeat 合併。
//
// 保護層級:
//   - activate:rate limit（IP + fingerprint），token 由此端點簽發所以無從要求預先提供
//   - validate:rate limit + VerifyLicenseToken（要求回帶 activate 的 PASETO token）
Route::prefix('licensing/v1')
    ->group(function (): void {
        Route::post('activate', [LicenseController::class, 'activate'])
            ->middleware('throttle:licensing-activate')
            ->name('licensing.activate');

        Route::post('validate', [ValidateController::class, 'validateLicense'])
            ->middleware(['throttle:licensing-validate', VerifyLicenseToken::class])
            ->name('licensing.validate');
    });
