<?php

declare(strict_types=1);

// ============================================================================
// 端對端驗證：ECDH Content Key Wrapping
// ============================================================================
//
// 此 script 模擬一個「Dart client 的完整 activate 流程」，但用 PHP + libsodium
// 實作。目的：在 plan 的 Task #10 階段，用真實 HTTP 通道驗證整個 wire protocol
// 從 client 送 request 到 unwrap content key 全部流程正確。
//
// 驗證項目：
//   1. Activate endpoint 對合法 request 回 200
//   2. Response body 完全不含頂層 content_key 欄位
//   3. PASETO token 可以被 signing public key 驗證簽章
//   4. Token claims 含 wrapped_content_key
//   5. 用 client private key + HKDF + XChaCha20-Poly1305 可以解出原始 content key
//   6. 解出的 content key 與 DB 中儲存的 raw 值 byte-perfect 匹配
//   7. 連續兩次 activate 的 wrap metadata 必定不同（forward secrecy）
//
// 前置條件：
//   - Admin server 在 http://localhost（sail 已啟動）
//   - DB 有 signing key + root key
//   - 有一筆 license 含 meta['content_key'] 且已知明文
//
// 執行方式：
//   ./vendor/bin/sail php tests/e2e_activate_verify.php \
//       <LICENSE_KEY> <EXPECTED_CONTENT_KEY_HEX>
// ============================================================================

require_once __DIR__.'/../vendor/autoload.php';

use ParagonIE\Paseto\Keys\AsymmetricPublicKey;
use ParagonIE\Paseto\Parser;
use ParagonIE\Paseto\Protocol\Version4;

if ($argc < 3) {
    fwrite(STDERR, "Usage: php e2e_activate_verify.php <LICENSE_KEY> <EXPECTED_CONTENT_KEY_HEX>\n");
    exit(2);
}

$licenseKey = $argv[1];
$expectedContentKeyHex = $argv[2];
$serverUrl = 'http://localhost/api/licensing/v1/activate';
$fingerprint = 'e2e-test-device-'.php_uname('n');

/**
 * 以 POST JSON 發 request 到 Laravel endpoint。
 * 使用 file_get_contents + stream_context，避免引入任何 shell 執行路徑。
 */
function httpPostJson(string $url, array $body): array
{
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
            'content' => json_encode($body),
            'ignore_errors' => true,
            'timeout' => 10,
        ],
    ]);

    $responseBody = @file_get_contents($url, false, $context);
    if ($responseBody === false) {
        throw new RuntimeException("HTTP request failed for {$url}");
    }

    // $http_response_header 是 file_get_contents 魔術變數
    $statusLine = $http_response_header[0] ?? '';
    if (! preg_match('#HTTP/\S+\s+(\d{3})#', $statusLine, $m)) {
        throw new RuntimeException("Unable to parse status line: {$statusLine}");
    }
    $status = (int) $m[1];

    if ($status !== 200) {
        throw new RuntimeException("HTTP {$status}: {$responseBody}");
    }

    return json_decode($responseBody, true);
}

function verifyAndExtractClaims(string $token, string $signingPubB64): array
{
    $publicKey = new AsymmetricPublicKey(base64_decode($signingPubB64), new Version4);
    $parser = Parser::getPublic($publicKey)->setNonExpiring(true);
    $parsed = $parser->parse($token);

    return $parsed->getClaims();
}

function unwrap(string $clientPriv, array $wrapped, string $licenseId, string $fingerprint): string
{
    $serverPub = base64_decode($wrapped['server_ephemeral_public_key']);
    $salt = base64_decode($wrapped['salt']);
    $nonce = base64_decode($wrapped['nonce']);
    $ciphertext = base64_decode($wrapped['ciphertext']);

    $shared = sodium_crypto_scalarmult($clientPriv, $serverPub);
    $info = 'wilson-content-key-wrap-v1'."\x00".$licenseId."\x00".$fingerprint;
    $wrapKey = hash_hkdf('sha256', $shared, 32, $info, $salt);

    $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
        $ciphertext,
        $wrapped['aad'],
        $nonce,
        $wrapKey
    );
    if ($plaintext === false) {
        throw new RuntimeException('unwrap failed: Poly1305 verification failed');
    }

    return $plaintext;
}

function check(string $desc, bool $condition): void
{
    if ($condition) {
        echo "  ✓ {$desc}\n";
    } else {
        echo "  ✗ {$desc}\n";
        exit(1);
    }
}

function runActivate(
    int $attempt,
    string $serverUrl,
    string $licenseKey,
    string $fingerprint,
    string $expectedContentKeyHex,
): array {
    echo "--- Activate attempt {$attempt} ---\n";

    $kp = sodium_crypto_box_keypair();
    $clientPriv = sodium_crypto_box_secretkey($kp);
    $clientPub = sodium_crypto_box_publickey($kp);

    $response = httpPostJson($serverUrl, [
        'license_key' => $licenseKey,
        'fingerprint' => $fingerprint,
        'client_ephemeral_public_key' => base64_encode($clientPub),
    ]);

    check('Response success=true', $response['success'] === true);
    $data = $response['data'];

    check('Response 頂層不含 content_key 欄位', ! isset($data['content_key']));
    check('Response 包含 token', isset($data['token']));
    check(
        'Response 包含 public_key_bundle.signing.public_key',
        isset($data['public_key_bundle']['signing']['public_key'])
    );

    $signingPub = $data['public_key_bundle']['signing']['public_key'];
    $claims = verifyAndExtractClaims($data['token'], $signingPub);
    check('PASETO Ed25519 簽章驗證通過', is_array($claims));

    check('Token claims 含 wrapped_content_key', isset($claims['wrapped_content_key']));
    $wrapped = $claims['wrapped_content_key'];
    check(
        '  alg = X25519+HKDF-SHA256+XChaCha20-Poly1305',
        $wrapped['alg'] === 'X25519+HKDF-SHA256+XChaCha20-Poly1305'
    );

    $contentKey = unwrap(
        $clientPriv,
        $wrapped,
        (string) $claims['license_id'],
        $fingerprint
    );
    $contentKeyHex = bin2hex($contentKey);
    check(
        'Unwrap 後的 content key 匹配 DB raw 值 (byte-perfect)',
        $contentKeyHex === $expectedContentKeyHex
    );
    check('Content key 長度為 32 bytes', strlen($contentKey) === 32);

    echo '  server_ephemeral_public_key: '.substr($wrapped['server_ephemeral_public_key'], 0, 24)."...\n";
    echo '  salt: '.substr($wrapped['salt'], 0, 24)."...\n";
    echo '  nonce: '.$wrapped['nonce']."\n";
    echo "\n";

    return $wrapped;
}

echo "=== E2E ECDH Content Key Wrapping Verification ===\n";
echo "License: {$licenseKey}\n";
echo "Fingerprint: {$fingerprint}\n";
echo "Expected content key (hex): {$expectedContentKeyHex}\n";
echo "\n";

$a = runActivate(1, $serverUrl, $licenseKey, $fingerprint, $expectedContentKeyHex);
$b = runActivate(2, $serverUrl, $licenseKey, $fingerprint, $expectedContentKeyHex);

echo "--- Forward Secrecy 驗證（attempt 1 vs attempt 2）---\n";
check(
    'server_ephemeral_public_key 每次都不同',
    $a['server_ephemeral_public_key'] !== $b['server_ephemeral_public_key']
);
check('salt 每次都不同', $a['salt'] !== $b['salt']);
check('nonce 每次都不同', $a['nonce'] !== $b['nonce']);
check('ciphertext 每次都不同', $a['ciphertext'] !== $b['ciphertext']);
echo "\n";

echo "All E2E checks passed.\n";
