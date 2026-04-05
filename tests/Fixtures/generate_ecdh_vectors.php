<?php

declare(strict_types=1);

// ============================================================================
// ECDH Content Key Wrapping - 互通測試向量生成器
// ============================================================================
//
// 用途：產生一份確定性（deterministic）的 test vectors JSON，供 PHP 端與
// Dart 端的單元測試共同驗證 XChaCha20-Poly1305 + X25519 + HKDF-SHA256 的
// wrap/unwrap 流程在兩種實作間 byte-perfect 互通。
//
// 執行方式：
//   ./vendor/bin/sail php tests/Fixtures/generate_ecdh_vectors.php \
//       > tests/Fixtures/ecdh_wrap_vectors.json
//
// 產生後請同步複製到 Flutter 專案：
//   cp tests/Fixtures/ecdh_wrap_vectors.json \
//      ../wilson-e-book-english/test/fixtures/ecdh_wrap_vectors.json
//
// 一旦 HKDF info 格式、AAD 值、或任一演算法參數改變，必須重新產生並同步。
// ============================================================================

const INFO_PREFIX = 'wilson-content-key-wrap-v1';
const AAD = 'wilson-content-key-wrap-v1';
const ALGORITHM = 'X25519+HKDF-SHA256+XChaCha20-Poly1305';

/**
 * 產生一組 deterministic 的 test vector
 *
 * @param  string  $description  人類可讀描述
 * @param  string  $seedHex  任意長度的 hex（作為 master seed，會用 HKDF 派生出兩個不同的 keypair seed）
 * @param  string  $licenseId  HKDF info 的一部分
 * @param  string  $fingerprint  HKDF info 的一部分
 * @param  int  $keyByte  用來產生 deterministic 的 content_key / salt / nonce
 */
function generateVector(
    string $description,
    string $seedHex,
    string $licenseId,
    string $fingerprint,
    int $keyByte,
): array {
    $masterSeed = hex2bin($seedHex);
    if ($masterSeed === false || strlen($masterSeed) < 16) {
        throw new RuntimeException('Master seed must decode to at least 16 bytes');
    }

    // 從 master seed 派生出兩把獨立的 keypair seed（確保 client ≠ server）
    $clientSeed = hash('sha256', $masterSeed.'::client', true);
    $serverSeed = hash('sha256', $masterSeed.'::server', true);

    // Deterministic X25519 keypair（sodium_crypto_box_* 家族用的就是 Curve25519 / X25519）
    $clientKp = sodium_crypto_box_seed_keypair($clientSeed);
    $clientPriv = sodium_crypto_box_secretkey($clientKp);
    $clientPub = sodium_crypto_box_publickey($clientKp);

    $serverKp = sodium_crypto_box_seed_keypair($serverSeed);
    $serverPriv = sodium_crypto_box_secretkey($serverKp);
    $serverPub = sodium_crypto_box_publickey($serverKp);

    // Deterministic raw content key、salt、nonce
    $rawContentKey = str_repeat(chr($keyByte), 32);
    $salt = str_repeat(chr(($keyByte + 0x11) & 0xFF), 32);
    $nonce = str_repeat(chr(($keyByte + 0x22) & 0xFF), 24);

    // ECDH（server 側）
    $sharedFromServer = sodium_crypto_scalarmult($serverPriv, $clientPub);

    // HKDF-SHA256 派生 wrap key
    $info = INFO_PREFIX."\x00".$licenseId."\x00".$fingerprint;
    $wrapKey = hash_hkdf('sha256', $sharedFromServer, 32, $info, $salt);

    // XChaCha20-Poly1305 encrypt
    $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
        $rawContentKey,
        AAD,
        $nonce,
        $wrapKey,
    );

    // ----- 自我驗證：模擬 client 側 -----
    $sharedFromClient = sodium_crypto_scalarmult($clientPriv, $serverPub);
    if ($sharedFromClient !== $sharedFromServer) {
        throw new RuntimeException('ECDH 雙邊 shared secret 不一致');
    }
    $decrypted = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
        $ciphertext,
        AAD,
        $nonce,
        $wrapKey,
    );
    if ($decrypted === false || $decrypted !== $rawContentKey) {
        throw new RuntimeException('自我驗證 decrypt 失敗');
    }

    return [
        'description' => $description,
        'license_id' => $licenseId,
        'fingerprint' => $fingerprint,
        'client_private_key_b64' => base64_encode($clientPriv),
        'client_public_key_b64' => base64_encode($clientPub),
        'server_private_key_b64' => base64_encode($serverPriv),
        'server_public_key_b64' => base64_encode($serverPub),
        'raw_content_key_b64' => base64_encode($rawContentKey),
        'salt_b64' => base64_encode($salt),
        'nonce_b64' => base64_encode($nonce),
        'aad' => AAD,
        'expected_wrap_key_b64' => base64_encode($wrapKey),
        'expected_ciphertext_b64' => base64_encode($ciphertext),
    ];
}

$output = [
    'algorithm' => ALGORITHM,
    'info_prefix' => INFO_PREFIX,
    'aad' => AAD,
    'generator' => 'PHP ext-sodium (wilson-e-book-admin)',
    'vectors' => [
        generateVector(
            description: '基本情境：integer license_id，Build.ID 格式 fingerprint',
            seedHex: str_repeat('0102030405060708', 8),
            licenseId: '6',
            fingerprint: 'TE1A.240213.009',
            keyByte: 0x11,
        ),
        generateVector(
            description: 'MAC 格式 fingerprint（帶冒號）',
            seedHex: str_repeat('a0b1c2d3e4f51617', 8),
            licenseId: '12345',
            fingerprint: 'AA:BB:CC:DD:EE:FF',
            keyByte: 0x42,
        ),
        generateVector(
            description: '大 license_id，純字元 fingerprint',
            seedHex: str_repeat('1a2b3c4d5e6f7080', 8),
            licenseId: '999999',
            fingerprint: 'test-device-xyz',
            keyByte: 0x7A,
        ),
    ],
];

echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
