<?php

use App\Http\Middleware\LogApiCall;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            LogApiCall::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * 授權 API 的 5xx 例外統一遮蔽。
         *
         * 除了 LicenseController::serverError() helper 已顯式處理的 4 個
         * 業務面 5xx 之外，這裡作為第二層防線攔截所有未捕獲的 Throwable
         * （例如資料庫斷線、PHP fatal、第三方套件 bug），確保不論後端
         * 發生什麼事，App 端看到的永遠是一致的 `SERVER_ERROR` 格式，
         * 絕不會暴露 stack trace、SQL、檔案路徑、設定細節。
         *
         * 完整 exception 會記錄到 `api` log channel，供後端團隊在
         * `storage/logs/api-YYYY-MM-DD.log` 查詢。
         *
         * 原本的 4xx 行為（VALIDATION_FAILED 422、INVALID_KEY 404、
         * SUSPENDED_LICENSE 423 等）都會保留，因為 render callback 在
         * 偵測到這些情境時會 return null，讓 Laravel 走預設處理。
         */
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/licensing/v1/*')) {
                return null;
            }

            // 保留原本的 4xx 行為：validation、已帶 status code 的 HTTP 例外
            if ($e instanceof ValidationException) {
                return null;
            }

            // Rate limit 超過：轉成專案統一的 `{success, error}` 格式，保留 Retry-After
            if ($e instanceof ThrottleRequestsException) {
                $retryAfter = $e->getHeaders()['Retry-After'] ?? 60;

                return response()->json([
                    'success' => false,
                    'error' => [
                        'code' => 'RATE_LIMITED',
                        'message' => 'Too many requests',
                    ],
                ], 429, ['Retry-After' => (string) $retryAfter]);
            }

            if ($e instanceof HttpExceptionInterface && $e->getStatusCode() < 500) {
                return null;
            }

            Log::channel('api')->error('[licensing-api] uncaught exception', [
                'internal_code' => 'UNCAUGHT_EXCEPTION',
                'exception_class' => $e::class,
                'exception_message' => $e->getMessage(),
                'exception_file' => $e->getFile(),
                'exception_line' => $e->getLine(),
                'exception_trace' => $e->getTraceAsString(),
                'path' => $request->path(),
                'method' => $request->method(),
            ]);

            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'SERVER_ERROR',
                    'message' => 'Server error',
                ],
            ], 500);
        });
    })->create();
