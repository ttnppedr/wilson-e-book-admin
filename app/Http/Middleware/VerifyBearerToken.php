<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use LucaLongo\Licensing\Contracts\TokenVerifier;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * PASETO token 驗證，僅驗簽章有效性，不做 body 欄位交叉比對。
 *
 * 只要求呼叫方持有任何一組合法的 PASETO token 即可通過（authentication only）。
 * 「這張授權現在還可不可用」屬於 authorization，由 EnsureLicenseUsable 負責——
 * 兩者刻意分開，因為 validate 端點必須在授權已取消／到期時仍然可達，才能回覆
 * 帶原因的 410/423 讓 App 分流；若把狀態檢查併進這裡，validate 會先被擋成 401，
 * App 就永遠問不出被拒的原因。
 */
class VerifyBearerToken
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
            Log::channel('api')->warning('[api] bearer token verify failed', [
                'internal_code' => 'TOKEN_VERIFY_FAILED',
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]);

            return $this->unauthorized('verify_failed');
        }

        $request->attributes->set('license_claims', $claims);

        return $next($request);
    }

    private function unauthorized(string $internalCode): JsonResponse
    {
        Log::channel('api')->info('[api] bearer token unauthorized', [
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
