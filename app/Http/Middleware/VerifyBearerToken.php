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
 * 只要求呼叫方持有任何一組合法的 PASETO token 即可存取（authentication only）。
 * 適用於所有需要驗證 bearer token 的 API 端點。
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
