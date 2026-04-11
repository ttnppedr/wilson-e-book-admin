<?php

namespace App\Http\Middleware;

use App\Models\License;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use LucaLongo\Licensing\Contracts\TokenVerifier;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * 要求 licensing API 呼叫方在 Authorization header 帶上 activate 時取得的 PASETO token。
 *
 * 驗證流程:
 *   1. 從 `Authorization: Bearer <token>` 取得 token
 *   2. 透過 vendor TokenVerifier 做 Ed25519 簽章、kid 查找、時間視窗檢查
 *   3. 交叉比對 token 的 `license_key_hash` 與 `usage_fingerprint` 是否對應 request body
 *   4. 任何失敗一律回 401 `INVALID_TOKEN`,不細分原因以避免 oracle attack
 *
 * 注意:token 刻意走 Authorization header 而不放 body,避免被 `LogApiCall` 寫進 api log。
 */
class VerifyLicenseToken
{
    public function __construct(private readonly TokenVerifier $tokenVerifier) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();
        if ($token === null || $token === '') {
            return $this->unauthorized('missing_bearer');
        }

        try {
            $claims = $this->tokenVerifier->verify($token);
        } catch (Throwable $exception) {
            Log::channel('api')->warning('[licensing-api] token verify failed', [
                'internal_code' => 'TOKEN_VERIFY_FAILED',
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]);

            return $this->unauthorized('verify_failed');
        }

        $licenseKey = (string) $request->input('license_key', '');
        $fingerprint = (string) $request->input('fingerprint', '');

        if ($licenseKey === '' || $fingerprint === '') {
            return $this->unauthorized('missing_body_fields');
        }

        $expectedLicenseKeyHash = License::hashKey($licenseKey);
        if (! hash_equals((string) ($claims['license_key_hash'] ?? ''), $expectedLicenseKeyHash)) {
            return $this->unauthorized('license_key_mismatch');
        }

        if (! hash_equals((string) ($claims['usage_fingerprint'] ?? ''), $fingerprint)) {
            return $this->unauthorized('fingerprint_mismatch');
        }

        $request->attributes->set('license_claims', $claims);

        return $next($request);
    }

    private function unauthorized(string $internalCode): JsonResponse
    {
        Log::channel('api')->info('[licensing-api] token unauthorized', [
            'internal_code' => $internalCode,
        ]);

        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'INVALID_TOKEN',
                'message' => 'License token is missing or invalid',
            ],
        ], 401);
    }
}
