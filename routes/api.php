<?php

use App\Http\Controllers\Api\LicenseController;
use App\Http\Controllers\Api\ValidateController;
use Illuminate\Support\Facades\Route;
use LucaLongo\Licensing\Http\Controllers\Api\HealthController;

// Vendor 的 api.enabled 已關閉，所有路由在此明確註冊。
// activate、validate 使用自訂 Controller，分別處理 ECDH content key wrapping 與 heartbeat 合併。
Route::prefix('licensing/v1')
    ->group(function (): void {
        Route::get('health', [HealthController::class, 'show'])->name('licensing.health');
        Route::post('activate', [LicenseController::class, 'activate'])->name('licensing.activate');
        Route::post('validate', [ValidateController::class, 'validateLicense'])->name('licensing.validate');
    });
