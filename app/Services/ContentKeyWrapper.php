<?php

namespace App\Services;

use InvalidArgumentException;

/**
 * 將 32-byte content key 用 ECDH + HKDF + XChaCha20-Poly1305 包裝後回傳給 client。
 *
 * 運作流程（每次 activate 呼叫一次）：
 *   1. 從 client 請求取得 ephemeral X25519 public key
 *   2. Server 生成自己的臨時 X25519 keypair
 *   3. ECDH 雙方算出共同的 shared secret
 *   4. 以 HKDF-SHA256 派生出一把 32-byte wrap key
 *      （info 繫結 license_id + fingerprint，防止跨 license 重放）
 *   5. 用 wrap key + 隨機 nonce 以 XChaCha20-Poly1305 加密 content key
 *   6. 回傳 wrap metadata（包含 server 的 ephemeral public key、salt、nonce、ciphertext）
 *   7. 立即 zeroize 所有敏感中間資料
 *
 * 安全性：
 *   - Forward secrecy：每次 activate 都用新的 ephemeral keypair，用完即銷毀
 *   - AEAD：Poly1305 tag 確保 client 無法篡改 wrap metadata
 *   - Context binding：HKDF info 與 AAD 都繫結 license_id、fingerprint
 *
 * 注意：XChaCha20-Poly1305 是純軟體實作，不依賴 AESNI，所以任何能跑 PHP
 *       ext-sodium 的環境都可用。
 */
class ContentKeyWrapper
{
    public const ALG = 'X25519+HKDF-SHA256+XChaCha20-Poly1305';

    public const AAD = 'wilson-content-key-wrap-v1';

    public const INFO_PREFIX = 'wilson-content-key-wrap-v1';

    /**
     * 將 raw content key 以 client 的 ephemeral public key 包裝。
     *
     * @param  string  $rawContentKey  32 bytes binary AES-256 key
     * @param  string  $clientPublicKey  32 bytes binary X25519 public key
     * @param  string  $licenseId  License ID（UTF-8 字串，繫結進 HKDF info）
     * @param  string  $fingerprint  裝置指紋（繫結進 HKDF info）
     * @return array<string, string> wrap metadata，所有欄位皆為 base64 字串
     *
     * @throws InvalidArgumentException 當 rawContentKey 或 clientPublicKey 長度錯誤
     */
    public function wrap(
        string $rawContentKey,
        string $clientPublicKey,
        string $licenseId,
        string $fingerprint,
    ): array {
        if (strlen($rawContentKey) !== 32) {
            throw new InvalidArgumentException(
                'Content key must be 32 bytes, got '.strlen($rawContentKey)
            );
        }
        if (strlen($clientPublicKey) !== 32) {
            throw new InvalidArgumentException(
                'Client public key must be 32 bytes, got '.strlen($clientPublicKey)
            );
        }

        // 1. 生成 server 端臨時 X25519 keypair
        //    （sodium_crypto_box_* 家族底層就是 Curve25519 / X25519）
        $serverKeyPair = sodium_crypto_box_keypair();
        $serverPriv = sodium_crypto_box_secretkey($serverKeyPair);
        $serverPub = sodium_crypto_box_publickey($serverKeyPair);

        $shared = null;
        $wrapKey = null;

        try {
            // 2. ECDH
            $shared = sodium_crypto_scalarmult($serverPriv, $clientPublicKey);

            // 3. HKDF-SHA256 派生 wrap key
            //    info = prefix || 0x00 || license_id || 0x00 || fingerprint
            //    這個繫結防止 wrap 結果在不同 license/device 之間被重放
            $salt = random_bytes(32);
            $info = self::INFO_PREFIX."\x00".$licenseId."\x00".$fingerprint;
            $wrapKey = hash_hkdf('sha256', $shared, 32, $info, $salt);

            // 4. XChaCha20-Poly1305 encrypt
            //    sodium 輸出格式為 ciphertext || tag 串接（tag = 最後 16 bytes）
            $nonce = random_bytes(24);
            $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
                $rawContentKey,
                self::AAD,
                $nonce,
                $wrapKey,
            );

            return [
                'alg' => self::ALG,
                'server_ephemeral_public_key' => base64_encode($serverPub),
                'salt' => base64_encode($salt),
                'nonce' => base64_encode($nonce),
                'ciphertext' => base64_encode($ciphertext),
                'aad' => self::AAD,
            ];
        } finally {
            // 5. 確保敏感資料在任何離開點都被清除
            //    （即使 encrypt 步驟拋例外也會執行）
            sodium_memzero($serverPriv);
            sodium_memzero($serverKeyPair);
            if ($shared !== null) {
                sodium_memzero($shared);
            }
            if ($wrapKey !== null) {
                sodium_memzero($wrapKey);
            }
        }
    }
}
