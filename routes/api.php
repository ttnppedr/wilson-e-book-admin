<?php

use App\Http\Controllers\Api\LicenseController;
use Illuminate\Support\Facades\Route;

// 覆寫 vendor 的 activate endpoint，加入動態 TTL 與 content_key。
// 注意：withRouting(api:) 自動加上 /api 前綴，所以這裡用 licensing/v1 而非 api/licensing/v1。
Route::prefix('licensing/v1')
    ->group(function (): void {
        Route::post('activate', [LicenseController::class, 'activate'])
            ->name('licensing.activate');
    });
