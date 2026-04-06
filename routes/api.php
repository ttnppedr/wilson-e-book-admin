<?php

use App\Http\Controllers\Api\LicenseController;
use Illuminate\Support\Facades\Route;
use LucaLongo\Licensing\Http\Controllers\Api\HealthController;
use LucaLongo\Licensing\Http\Controllers\Api\LicenseController as VendorLicenseController;
use LucaLongo\Licensing\Http\Controllers\Api\TokenController;
use LucaLongo\Licensing\Http\Controllers\Api\UsageController;

// Vendor 的 api.enabled 已關閉，所有路由在此明確註冊。
// activate 使用自訂 Controller（ECDH content key wrapping），其餘沿用 vendor controller。
Route::prefix('licensing/v1')
    ->group(function (): void {
        Route::get('health', [HealthController::class, 'show'])->name('licensing.health');
        Route::post('activate', [LicenseController::class, 'activate'])->name('licensing.activate');
        Route::post('deactivate', [VendorLicenseController::class, 'deactivate'])->name('licensing.deactivate');
        Route::post('refresh', [VendorLicenseController::class, 'refresh'])->name('licensing.refresh');
        Route::post('validate', [VendorLicenseController::class, 'validateLicense'])->name('licensing.validate');
        Route::post('heartbeat', [UsageController::class, 'heartbeat'])->name('licensing.heartbeat');
        Route::get('licenses/{licenseKey}', [VendorLicenseController::class, 'show'])->name('licensing.licenses.show');
        Route::post('token', [TokenController::class, 'issue'])->name('licensing.token.issue');
    });
