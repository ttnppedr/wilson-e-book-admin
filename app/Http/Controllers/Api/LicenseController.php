<?php

namespace App\Http\Controllers\Api;

use App\Services\ContentKeyWrapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use LucaLongo\Licensing\Enums\KeyStatus;
use LucaLongo\Licensing\Enums\KeyType;
use LucaLongo\Licensing\Enums\LicenseStatus;
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
     *
     * 必須是 protected：ValidateController（子類）簽發驗證收據時也要用。
     */
    protected const PERPETUAL_TOKEN_TTL_DAYS = 36500;

    /**
     * 本次回應要一併下發的 signing kid：取自剛簽出 token 的 footer。
     *
     * vendor 的 buildPublicKeyBundle() 沒有參數可傳，只能用這個 request-scoped
     * 欄位把「誰簽了這顆 token」帶進去；由 extractTokenDetails() 設定並在
     * finally 清空，避免跨請求殘留。
     */
    protected ?string $bundleSigningKid = null;

    /**
     * ⚠️ 此方法複製自 vendor LicenseController::activate()，在固定位置插入
     *    client ephemeral key 驗證、ECDH content key wrap、以及把 wrapped
     *    metadata 注入 PASETO extra_claims 的邏輯。升級
     *    masterix21/laravel-licensing 時，務必重新對齊 parent::activate() 的
     *    最新版本。
     *    對齊版本：vendor 2.2.1
     */
    public function activate(Request $request): JsonResponse
    {
        $payload = $this->validate($request, [
            'license_key' => ['required', 'string'],
            'fingerprint' => ['required', 'string', 'max:255'],
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

    /**
     * 覆寫 vendor formatLicense() 加上 name 欄位，並把 usage summary 改為
     * 單次 COUNT：vendor 版的 `active_usages`（`activeUsages()->count()`）與
     * `available_seats`（`getAvailableSeats()` 內部再跑一次相同 COUNT）對同一個
     * 條件重複查詢兩次，而 activate/validate（心跳）都是熱路徑。此處先以
     * `$includeUsageSummary = false` 呼叫 parent 略過其 summary，再自行以
     * 單次 COUNT 補上兩個欄位（`available_seats` 演算法同 vendor
     * `License::getAvailableSeats()`）。
     *
     * 對齊版本：vendor 2.2.1
     */
    protected function formatLicense(License $license, bool $includeUsageSummary = true): array
    {
        $data = parent::formatLicense($license, false);
        $data['name'] = $license->name;

        if ($includeUsageSummary) {
            $activeUsages = $license->activeUsages()->count();
            $data['active_usages'] = $activeUsages;
            $data['available_seats'] = max(0, $license->max_usages - $activeUsages);
        }

        return $data;
    }

    /**
     * 覆寫 vendor guardLicenseState() 拆分 suspended 與 cancelled。
     *
     * vendor 原版把兩者合併成同一個 SUSPENDED_LICENSE (423)，但 App 端需要對
     * 這兩種情況做不同 UX 分流：cancelled 應清本地金鑰，suspended 應顯示 disabled
     * 輸入框等待 admin 恢復。透過子類別動態分派，activate 與 validate endpoint
     * （ValidateController extends LicenseController）都會套用此覆寫版本。
     *
     * 對齊版本：vendor 2.2.1
     */
    protected function guardLicenseState(License $license): ?JsonResponse
    {
        if ($license->status === LicenseStatus::Cancelled) {
            return $this->error('CANCELLED_LICENSE', 'License has been cancelled', 410);
        }

        if ($license->status === LicenseStatus::Suspended) {
            return $this->error('SUSPENDED_LICENSE', 'License is suspended', 423);
        }

        // 產品決策：**無寬限期，到期即失效**。原本的 `&& ! isInGracePeriod()`
        // 讓 grace 授權繼續拿得到 token 與 content key；現在一律拒絕。
        //
        // `isExpired()` 是純日期判斷（expires_at 已過），因此**不依賴每日排程**
        // ——授權在到期當下就會被拒，不必等 licensing:check-expirations 跑過。
        if ($license->isExpired()) {
            return $this->error('EXPIRED_LICENSE', 'License is expired', 410);
        }

        // vendor 的 LicenseStatus::isUsable() 仍把 Grace 算成可用（enum 在
        // vendor，改不了）。本專案不接受 grace，故顯式擋掉——上面的日期檢查
        // 理論上已涵蓋（grace ⇒ 必已過期），這是不依賴該蘊含關係的第二道。
        if ($license->status === LicenseStatus::Grace || ! $license->isUsable()) {
            return $this->error('LICENSE_NOT_ACTIVE', 'License is not active', 403);
        }

        return null;
    }

    /**
     * 覆寫 vendor extractTokenDetails()，把「剛簽出這顆 token 的 kid」交給
     * buildPublicKeyBundle()。
     *
     * 為什麼：vendor 的 bundle 另外查一次「目前 active 的 signing key」，與簽
     * token 的解析（WilsonPasetoTokenService::issue() → findActiveSigning($scope)）
     * 是兩套條件——前者看 valid_from/valid_until 時間窗、後者不看；前者不過濾
     * scope、後者過濾。任一軸分歧就會下發「驗不了這顆 token」的憑證，App 端的
     * 憑證鏈或簽章驗證必然失敗（bundle 為 null 時 App 直接報「伺服器未回傳授權
     * 憑證」而無法啟用）。改以 token footer 的 kid 回查，兩者永遠是同一把。
     * 對齊版本：vendor 2.2.1
     */
    protected function extractTokenDetails(string $token): array
    {
        $this->bundleSigningKid = $this->extractKidFromFooter($token);

        try {
            return parent::extractTokenDetails($token);
        } finally {
            $this->bundleSigningKid = null;
        }
    }

    /**
     * 從 PASETO v4.public token 的 footer（base64url，第 4 段）取出 kid。
     * 格式不符時回 null，由 buildPublicKeyBundle() 走 fallback。
     */
    protected function extractKidFromFooter(string $token): ?string
    {
        $parts = explode('.', $token);
        if (count($parts) < 4) {
            return null;
        }

        $b64 = strtr($parts[3], '-_', '+/');
        $b64 .= str_repeat('=', (4 - strlen($b64) % 4) % 4);
        $footer = base64_decode($b64, true);
        if ($footer === false) {
            return null;
        }

        $decoded = json_decode($footer, true);

        return is_array($decoded) && is_string($decoded['kid'] ?? null)
            ? $decoded['kid']
            : null;
    }

    /**
     * 覆寫 vendor buildPublicKeyBundle()。
     *
     * 解析順序：
     *   1. 以本次 token footer 的 kid 精確回查——唯一能保證「這份憑證驗得了這顆
     *      token」的做法。刻意不過濾 status/時間窗：輪替當下剛被 revoke 或剛過
     *      valid_until 的 key 也必須能下發，否則同一個回應裡的 token 與憑證會
     *      自相矛盾。
     *   2. fallback（footer 無 kid 等異常）：type=Signing + status=Active +
     *      created_at desc。刻意**不加**時間窗、**不過濾** scope，與 issue() 的
     *      findActiveSigning() 對齊。
     * 對齊版本：vendor 2.2.1 — root 公鑰改用 certificateAuthority->getRootPublicKey()。
     */
    protected function buildPublicKeyBundle(): ?array
    {
        $signingKey = $this->bundleSigningKid !== null
            ? LicensingKey::findByKid($this->bundleSigningKid)
            : null;

        $signingKey ??= LicensingKey::query()
            ->where('type', KeyType::Signing)
            ->where('status', KeyStatus::Active)
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
                'public_key' => $this->certificateAuthority->getRootPublicKey(),
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
