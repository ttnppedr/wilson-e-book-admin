<?php

namespace App\Services;

use LucaLongo\Licensing\Models\License;
use LucaLongo\Licensing\Models\LicenseUsage;
use LucaLongo\Licensing\Models\LicensingKey;
use LucaLongo\Licensing\Services\CertificateAuthorityService;
use LucaLongo\Licensing\Services\PasetoTokenService;
use ParagonIE\Paseto\Builder;
use ParagonIE\Paseto\Keys\AsymmetricSecretKey;
use ParagonIE\Paseto\Protocol\Version4;

/**
 * 覆寫 masterix21/laravel-licensing 的 PasetoTokenService，
 * 在 `issue()` 階段支援透過 `$options['extra_claims']` 注入自訂 PASETO claims。
 *
 * 為什麼需要覆寫：
 *   vendor 版本的 `PasetoTokenService::issue()` 不接受 extra claims，$options
 *   只處理 `ttl_days` 與 `issuer`。我們需要在 token payload 加入 `wrapped_content_key`
 *   claim（由 Ed25519 signing key 簽章保護），因此必須在 `Builder::setClaims()` 被
 *   呼叫前介入。由於 vendor 沒提供 protected 擴充點，這裡採「完整複製 parent::issue()
 *   邏輯並插入 extra claim 合併」的策略。
 *
 * ⚠️ 升級 masterix21/laravel-licensing 時務必重新對齊 parent::issue()。
 *    對齊版本：vendor 2.0.0
 *    唯一差異在下方 `$claims = ...` 後方的 extra_claims 合併區塊。
 *
 * 設定方式：在 `config/licensing.php` 的 `offline_token.service` 指向此類別。
 */
class WilsonPasetoTokenService extends PasetoTokenService
{
    /**
     * @param  array{ttl_days?: int, issuer?: string, extra_claims?: array<string, mixed>}  $options
     */
    public function issue(License $license, LicenseUsage $usage, array $options = []): string
    {
        // ---- 以下邏輯對齊 parent::issue()，只在標記處插入 extra_claims 合併 ----
        $scope = $license->scope;
        $signingKey = LicensingKey::findActiveSigning($scope);
        if (! $signingKey && $scope !== null) {
            $signingKey = LicensingKey::findActiveSigning();
        }
        if (! $signingKey) {
            throw new \RuntimeException(
                $scope
                    ? "No active signing key found for scope: {$scope->name}"
                    : 'No active signing key found'
            );
        }

        $privateKeyBase64 = $signingKey->getPrivateKey();
        if (! $privateKeyBase64) {
            throw new \RuntimeException('Private key not available');
        }
        $privateKey = new AsymmetricSecretKey(base64_decode($privateKeyBase64), new Version4);

        $ttlDays = $options['ttl_days'] ?? $license->getTokenTtlDays();
        $issuer = $options['issuer']
            ?? config('licensing.offline_token.issuer', 'laravel-licensing');

        $now = now()->toImmutable();
        $expiration = $ttlDays < 0
            ? $now->subDays(abs($ttlDays))
            : $now->addDays($ttlDays);

        $forceOnlineDays = $license->getForceOnlineAfterDays();
        $forceOnlineAfter = $forceOnlineDays < 0
            ? $now->subDays(abs($forceOnlineDays))
            : $now->addDays($forceOnlineDays);

        $claims = [
            'kid' => $signingKey->kid,
            'license_id' => $license->id,
            'license_key_hash' => $license->key_hash,
            'usage_fingerprint' => $usage->usage_fingerprint,
            'status' => $license->status->value,
            'max_usages' => $license->max_usages,
            'force_online_after' => $forceOnlineAfter->format('c'),
            'licensable_type' => $license->licensable_type,
            'licensable_id' => $license->licensable_id,
        ];

        if ($license->expires_at) {
            $claims['license_expires_at'] = $license->expires_at->format('c');
        }

        if ($license->isInGracePeriod()) {
            $graceDays = $license->getGraceDays();
            $graceUntil = $license->expires_at->addDays($graceDays);
            $claims['grace_until'] = $graceUntil->format('c');
        }

        // ============================================================
        // 唯一新增邏輯：合併 extra_claims（保護保留字不被覆蓋）
        // ============================================================
        $extraClaims = $options['extra_claims'] ?? [];
        if (! empty($extraClaims) && is_array($extraClaims)) {
            $reservedKeys = [
                'kid', 'license_id', 'license_key_hash', 'usage_fingerprint',
                'status', 'max_usages', 'force_online_after', 'licensable_type',
                'licensable_id', 'license_expires_at', 'grace_until',
                'iat', 'nbf', 'exp', 'sub', 'iss',
            ];
            foreach ($extraClaims as $key => $value) {
                if (in_array($key, $reservedKeys, true)) {
                    throw new \RuntimeException(
                        "extra_claims cannot override reserved PASETO claim: {$key}"
                    );
                }
                $claims[$key] = $value;
            }
        }
        // ============================================================

        $token = Builder::getPublic($privateKey, new Version4)
            ->setIssuedAt($now)
            ->setNotBefore($now)
            ->setExpiration($expiration)
            ->setSubject((string) $license->id)
            ->setIssuer($issuer)
            ->setClaims($claims);

        $ca = app(CertificateAuthorityService::class);
        $footer = json_encode([
            'kid' => $signingKey->kid,
            'chain' => $ca->getCertificateChain($signingKey->kid),
        ]);

        return $token->setFooter($footer)->toString();
    }
}
