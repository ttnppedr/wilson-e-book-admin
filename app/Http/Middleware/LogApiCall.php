<?php

namespace App\Http\Middleware;

use App\Models\License;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class LogApiCall
{
    /**
     * 記錄前一律遮蔽的鍵名，request 與 response body 皆適用（遞迴比對）。
     *
     * 這些欄位本身就是憑證材料，落地 log 即等同外洩：
     *   - `license_key`：授權碼明文。DB 刻意只存 HMAC `key_hash`，log 不該成為
     *     系統中唯一保有明文的地方——持有它即可重打 activate 換到 content key。
     *   - `token` / `validation_token`：activate 與 validate 簽發的 PASETO bearer，
     *     持有即可存取全部內容 API。
     *   - `wrapped_content_key`：ECDH 包裝後的內容金鑰。
     *
     * 採「列出要遮的鍵名」而非「只擋已知端點」：日後新增端點只要沿用同樣的欄位
     * 命名就自動受保護。比對不分大小寫，避免拼寫差異繞過。
     *
     * @var list<string>
     */
    private const REDACTED_KEYS = [
        'license_key',
        'password',
        'token',
        'validation_token',
        'wrapped_content_key',
        'private_key',
        'encrypted_key',
        'passphrase',
    ];

    private const REDACTED_PLACEHOLDER = '[REDACTED]';

    /** `license_key_hash_prefix` 取 key_hash 的前綴長度 */
    private const KEY_HASH_PREFIX_LENGTH = 12;

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
            'request' => $this->buildRequestPayload($request),
            'response' => $shouldLogResponseBody && $response instanceof JsonResponse
                ? $this->redact((array) $response->getData(assoc: true))
                : null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $response;
    }

    /**
     * 組出可安全落地的 request payload。
     *
     * 遮蔽掉授權碼之後仍要能回答「這筆請求是哪一張授權」——否則客服收到「啟用
     * 失敗」的回報就無從追查。改記 `key_hash` 的前綴：它是 HMAC 的截斷值、不可
     * 回推明文，卻能直接對到 `licenses.key_hash` 那一列。
     *
     * @return array<array-key, mixed>
     */
    private function buildRequestPayload(Request $request): array
    {
        $payload = $this->redact($request->all());

        $licenseKey = $request->input('license_key');
        if (is_string($licenseKey) && $licenseKey !== '') {
            $payload['license_key_hash_prefix'] = substr(
                License::hashKey($licenseKey),
                0,
                self::KEY_HASH_PREFIX_LENGTH,
            );
        }

        return $payload;
    }

    /**
     * 遞迴遮蔽 REDACTED_KEYS 命中的鍵。
     *
     * 必須遞迴：vendor `buildLicenseResponse()` 組出的是巢狀結構（license / usage
     * 各自成一層），只掃頂層會漏掉巢狀位置的憑證欄位。
     *
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    private function redact(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::REDACTED_KEYS, true)) {
                $payload[$key] = self::REDACTED_PLACEHOLDER;

                continue;
            }

            if (is_array($value)) {
                $payload[$key] = $this->redact($value);
            }
        }

        return $payload;
    }
}
