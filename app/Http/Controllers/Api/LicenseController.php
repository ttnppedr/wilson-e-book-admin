<?php

namespace App\Http\Controllers\Api;

use App\Services\ContentKeyWrapper;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LucaLongo\Licensing\Http\Controllers\Api\LicenseController as BaseLicenseController;
use LucaLongo\Licensing\Models\License;
use LucaLongo\Licensing\Models\LicensingKey;

class LicenseController extends BaseLicenseController
{
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
        if (! $license->isOfflineTokenEnabled() || ! $license->expires_at) {
            return $this->error(
                'TOKEN_REQUIRED',
                'Offline token must be enabled and license must have an expiry',
                500,
            );
        }

        $rawContentKey = $this->extractRawContentKey($license);
        if ($rawContentKey === null) {
            return $this->error(
                'MISSING_CONTENT_KEY',
                'License does not have a content key configured',
                500,
            );
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
        } catch (\Throwable $exception) {
            sodium_memzero($rawContentKey);

            return $this->error('WRAP_FAILED', $exception->getMessage(), 500);
        }
        sodium_memzero($rawContentKey);

        try {
            $remainingDays = max(1, (int) ceil(now()->diffInDays($license->expires_at, absolute: true)));
            // 把 wrapped metadata 作為 extra claim 塞進 PASETO token，由 signing
            // key 的 Ed25519 簽章保護，避免 client 篡改 wrap 參數。
            $token = $this->licensing->issueToken($license, $usage, [
                'ttl_days' => $remainingDays,
                'extra_claims' => [
                    'wrapped_content_key' => $wrapped,
                ],
            ]);
        } catch (\Throwable $exception) {
            return $this->error('TOKEN_ISSUE_FAILED', $exception->getMessage(), 500);
        }

        return $this->success($this->buildLicenseResponse($license->fresh(), $usage, $token));
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
