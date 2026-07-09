<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class LogApiCall
{
    public function handle(Request $request, Closure $next): Response
    {
        $startTime = microtime(true);

        /** @var Response $response */
        $response = $next($request);

        $duration = round((microtime(true) - $startTime) * 1000, 2);

        $status = $response->getStatusCode();

        // GET 成功（含 304）不記 response body：SetResponseEtag 轉 304 時只清 HTTP
        // content，JsonResponse 內部 $data 仍是全量，照記會抵銷 ETag 省下的成本。
        // 錯誤與寫入類請求保留全量 body 供除錯。
        $shouldLogResponseBody = $status >= 400 || ! $request->isMethod('GET');

        Log::channel('api')->info(json_encode([
            'method' => $request->method(),
            'uri' => $request->getRequestUri(),
            'status' => $status,
            'duration_ms' => $duration,
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'headers' => collect($request->headers->all())
                ->except(['authorization', 'cookie'])
                ->all(),
            'request' => $request->except(['password']),
            'response' => $shouldLogResponseBody && $response instanceof JsonResponse
                ? $response->getData(assoc: true)
                : null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $response;
    }
}
