<?php

namespace App\Http\Controllers\Api;

use App\Services\ContentKeyWrapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use LucaLongo\Licensing\Http\Controllers\Api\LicenseController as BaseLicenseController;
use LucaLongo\Licensing\Models\License;
use LucaLongo\Licensing\Models\LicensingKey;
use Throwable;

class LicenseController extends BaseLicenseController
{
    /**
     * 無到期日授權 (永久授權) 的 offline token TTL (天)。
     *
     * 客戶端沒有 token refresh 機制，永久授權必須「一次啟用即長期可用」，
     * 100 年 (36500 天) 實質上超過任何合理使用年限，等同永不過期。
     * 有到期日的授權仍維持「TTL 綁定剩餘天數」的動態計算，確保 token
     * 不會活得比授權本身久。
     */
    private const PERPETUAL_TOKEN_TTL_DAYS = 36500;

    /**
     * ⚠️ 此方法複製自 vendor LicenseController::activate()，在固定位置插入
     *    client ephemeral key 驗證、ECDH content key wrap、以及把 wrapped
     *    metadata 注入 PASETO extra_claims 的邏輯。升級
     *    masterix21/laravel-licensing 時，務必重新對齊 parent::activate() 的
     *    最新版本。
     *    對齊版本：vendor/masterix21/laravel-licensing/src/Http/Controllers/Api/LicenseController.php
     */
    public function activate(Request $request): JsonResponse
    {
        $payload = $this->validate($request, [
            'license_key' => ['required', 'string'],
            'fingerprint' => ['required', 'string'],
            'client_ephemeral_public_key' => ['required', 'string'],
            'metadata' => ['nullable', 'array'],
        ]);

        $clientPub = base64_decode($payload['client_ephemeral_public_key'], true);
        if ($clientPub === false || strlen($clientPub) !== 32) {
            return $this->error(
                'INVALID_EPHEMERAL_KEY',
                'client_ephemeral_public_key must be base64 of a 32-byte X25519 public key',
                400,
            );
        }

        $license = $this->findLicense($payload['license_key']);
        if (! $license) {
            return $this->error('INVALID_KEY', 'License key is invalid or not found', 404);
        }

        if ($license->status->canActivate()) {
            $license->activate();
            $license->refresh();
        }

        if ($response = $this->guardLicenseState($license)) {
            return $response;
        }

        // 把 TOKEN_REQUIRED 前置條件放在所有副作用（register、wrap）之前，
        // 避免 offline token 未啟用時白白消耗 usage seat 與進行 ECDH 運算。
        // 允許 expires_at = null 的永久授權（TTL 於下方動態決定）。
        if (! $license->isOfflineTokenEnabled()) {
            return $this->serverError('TOKEN_REQUIRED', context: [
                'license_id' => $license->id,
            ]);
        }

        $rawContentKey = $this->extractRawContentKey($license);
        if ($rawContentKey === null) {
            return $this->serverError('MISSING_CONTENT_KEY', context: [
                'license_id' => $license->id,
                'scope_id' => $license->license_scope_id,
            ]);
        }

        $metadata = $payload['metadata'] ?? [];
        try {
            $usage = $this->licensing->register($license, $payload['fingerprint'], $metadata);
        } catch (\RuntimeException $exception) {
            sodium_memzero($rawContentKey);

            return $this->mapUsageException($exception);
        }

        try {
            $wrapped = app(ContentKeyWrapper::class)->wrap(
                $rawContentKey,
                $clientPub,
                (string) $license->id,
                $payload['fingerprint'],
            );
        } catch (Throwable $exception) {
            sodium_memzero($rawContentKey);

            return $this->serverError('WRAP_FAILED', $exception, [
                'license_id' => $license->id,
                'usage_id' => $usage->id,
            ]);
        }
        sodium_memzero($rawContentKey);

        try {
            // 有到期日：TTL 綁定授權剩餘天數，確保 token 不會活得比授權本身久。
            // 無到期日 (永久授權)：TTL 設為 PERPETUAL_TOKEN_TTL_DAYS，實質上永不過期。
            $ttlDays = $license->expires_at !== null
                ? max(1, (int) ceil(now()->diffInDays($license->expires_at, absolute: true)))
                : self::PERPETUAL_TOKEN_TTL_DAYS;

            // 把 wrapped metadata 作為 extra claim 塞進 PASETO token，由 signing
            // key 的 Ed25519 簽章保護，避免 client 篡改 wrap 參數。
            $token = $this->licensing->issueToken($license, $usage, [
                'ttl_days' => $ttlDays,
                'extra_claims' => [
                    'wrapped_content_key' => $wrapped,
                ],
            ]);
        } catch (Throwable $exception) {
            return $this->serverError('TOKEN_ISSUE_FAILED', $exception, [
                'license_id' => $license->id,
                'usage_id' => $usage->id,
            ]);
        }

        return $this->success($this->buildLicenseResponse($license->fresh(), $usage, $token));
    }

    /**
     * 針對所有 API 5xx 回應的統一出口。
     *
     * 資安考量：
     *   - 回傳給 client 的 body 一律為 generic `SERVER_ERROR` / `Server error`，
     *     不暴露任何內部狀態、設定細節或 exception 訊息。
     *   - 完整的 `internal_code`、exception class/message/trace 以及呼叫現場
     *     傳入的 context（例如 license_id、usage_id、scope_id）會記錄到
     *     `api` log channel（`storage/logs/api-YYYY-MM-DD.log`），供後端團隊
     *     查詢排錯，但**絕不**出現在 HTTP response 中。
     *
     * 使用慣例：
     *   - `internal_code`：語意化的錯誤分類（如 `WRAP_FAILED`、`MISSING_CONTENT_KEY`），
     *     僅作為 log 分類用，不會回傳給 client。
     *   - `$exception`：如果觸發 5xx 的是 catch 到的 Throwable，就傳進來，會
     *     被完整序列化到 log。
     *   - `$context`：額外的 log 欄位（license_id、usage_id 等），方便查詢時定位。
     */
    protected function serverError(string $internalCode, ?Throwable $exception = null, array $context = []): JsonResponse
    {
        Log::channel('api')->error('[licensing-api] server error', [
            'internal_code' => $internalCode,
            'exception_class' => $exception ? $exception::class : null,
            'exception_message' => $exception?->getMessage(),
            'exception_file' => $exception?->getFile(),
            'exception_line' => $exception?->getLine(),
            'exception_trace' => $exception?->getTraceAsString(),
            'context' => $context,
        ]);

        return $this->error('SERVER_ERROR', 'Server error', 500);
    }

    protected function formatLicense(License $license, bool $includeUsageSummary = true): array
    {
        $data = parent::formatLicense($license, $includeUsageSummary);
        $data['name'] = $license->name;

        return $data;
    }

    /**
     * 覆寫 vendor buildPublicKeyBundle()。
     *
     * vendor 的 findActiveSigning() 預設用 forScope(null) 只找無 scope 的 key，
     * 但本專案的 signing key 綁定 scope，需忽略 scope 限制查找。
     */
    protected function buildPublicKeyBundle(): ?array
    {
        $signingKey = LicensingKey::activeSigning()
            ->orderBy('created_at', 'desc')
            ->first();

        $rootKey = LicensingKey::findActiveRoot();

        if (! $signingKey || ! $rootKey) {
            return null;
        }

        return [
            'signing' => array_filter([
                'kid' => $signingKey->kid,
                'public_key' => $signingKey->getPublicKey(),
                'certificate' => $signingKey->getCertificate(),
                'valid_from' => $signingKey->valid_from?->format('c'),
                'valid_until' => $signingKey->valid_until?->format('c'),
            ], fn ($v) => $v !== null),
            'root' => array_filter([
                'kid' => $rootKey->kid,
                'public_key' => $rootKey->getPublicKey(),
                'valid_from' => $rootKey->valid_from?->format('c'),
                'valid_until' => $rootKey->valid_until?->format('c'),
            ], fn ($v) => $v !== null),
            'issued_at' => now()->format('c'),
        ];
    }

    /**
     * 透過 License → Scope → ContentEncryptionKey 關聯取得 32-byte binary content key。
     *
     * ContentEncryptionKey.encrypted_key 透過 encrypted cast 解密後為 base64 字串，
     * 解碼後回傳 raw bytes。
     */
    protected function extractRawContentKey(License $license): ?string
    {
        $cek = $license->scope?->contentEncryptionKey;
        if (! $cek) {
            return null;
        }

        $b64 = $cek->encrypted_key;
        if (! is_string($b64) || $b64 === '') {
            return null;
        }

        $raw = base64_decode($b64, true);
        if ($raw === false || strlen($raw) !== 32) {
            return null;
        }

        return $raw;
    }
}
