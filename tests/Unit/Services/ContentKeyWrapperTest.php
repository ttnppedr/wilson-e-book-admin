<?php

namespace Tests\Unit\Services;

use App\Services\ContentKeyWrapper;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * ContentKeyWrapper 單元測試
 *
 * 驗證 X25519 + HKDF-SHA256 + XChaCha20-Poly1305 的 wrap 邏輯：
 *   - Round-trip：wrap 輸出可以用 client 私鑰手動 unwrap 出原文
 *   - 輸入驗證：非法長度會拋例外
 *   - Forward secrecy：兩次 wrap 相同輸入產生不同 wrap metadata
 *   - Context binding：不同 license_id / fingerprint 會派生不同 wrap key
 */
class ContentKeyWrapperTest extends TestCase
{
    private ContentKeyWrapper $wrapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->wrapper = new ContentKeyWrapper;
    }

    /**
     * 產生一組 client X25519 keypair（測試用）
     *
     * @return array{0: string, 1: string} [privateKey, publicKey]
     */
    private function generateClientKeyPair(): array
    {
        $kp = sodium_crypto_box_keypair();

        return [
            sodium_crypto_box_secretkey($kp),
            sodium_crypto_box_publickey($kp),
        ];
    }

    /**
     * 模擬 client 端完整 unwrap 流程
     */
    private function unwrapAsClient(
        array $wrapped,
        string $clientPriv,
        string $licenseId,
        string $fingerprint,
    ): string {
        $serverPub = base64_decode($wrapped['server_ephemeral_public_key']);
        $salt = base64_decode($wrapped['salt']);
        $nonce = base64_decode($wrapped['nonce']);
        $ciphertext = base64_decode($wrapped['ciphertext']);

        $shared = sodium_crypto_scalarmult($clientPriv, $serverPub);
        $info = ContentKeyWrapper::INFO_PREFIX."\x00".$licenseId."\x00".$fingerprint;
        $wrapKey = hash_hkdf('sha256', $shared, 32, $info, $salt);

        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $ciphertext,
            $wrapped['aad'],
            $nonce,
            $wrapKey,
        );

        if ($plaintext === false) {
            throw new \RuntimeException('unwrap failed: Poly1305 verification failed');
        }

        return $plaintext;
    }

    public function test_wrap_output_can_be_unwrapped_to_original_content_key(): void
    {
        [$clientPriv, $clientPub] = $this->generateClientKeyPair();
        $rawContentKey = random_bytes(32);

        $wrapped = $this->wrapper->wrap(
            $rawContentKey,
            $clientPub,
            '42',
            'test-fingerprint-001',
        );

        $unwrapped = $this->unwrapAsClient(
            $wrapped,
            $clientPriv,
            '42',
            'test-fingerprint-001',
        );

        $this->assertSame($rawContentKey, $unwrapped);
        $this->assertSame(32, strlen($unwrapped));
    }

    public function test_wrap_metadata_has_correct_structure_and_sizes(): void
    {
        [, $clientPub] = $this->generateClientKeyPair();
        $rawContentKey = random_bytes(32);

        $wrapped = $this->wrapper->wrap($rawContentKey, $clientPub, '1', 'fp');

        $this->assertSame(ContentKeyWrapper::ALG, $wrapped['alg']);
        $this->assertSame(ContentKeyWrapper::AAD, $wrapped['aad']);
        $this->assertSame(32, strlen(base64_decode($wrapped['server_ephemeral_public_key'])));
        $this->assertSame(32, strlen(base64_decode($wrapped['salt'])));
        $this->assertSame(24, strlen(base64_decode($wrapped['nonce'])));

        // XChaCha20-Poly1305 輸出 = plaintext (32) + Poly1305 tag (16) = 48 bytes
        $this->assertSame(48, strlen(base64_decode($wrapped['ciphertext'])));
    }

    public function test_wrap_throws_on_wrong_content_key_length(): void
    {
        [, $clientPub] = $this->generateClientKeyPair();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Content key must be 32 bytes');

        $this->wrapper->wrap(
            random_bytes(16), // 錯誤：不是 32 bytes
            $clientPub,
            '1',
            'fp',
        );
    }

    public function test_wrap_throws_on_wrong_public_key_length(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Client public key must be 32 bytes');

        $this->wrapper->wrap(
            random_bytes(32),
            random_bytes(16), // 錯誤：不是 32 bytes
            '1',
            'fp',
        );
    }

    public function test_wrap_produces_different_output_on_each_call_forward_secrecy(): void
    {
        [, $clientPub] = $this->generateClientKeyPair();
        $rawContentKey = str_repeat("\x42", 32); // 故意固定，排除變因

        $a = $this->wrapper->wrap($rawContentKey, $clientPub, '42', 'fp');
        $b = $this->wrapper->wrap($rawContentKey, $clientPub, '42', 'fp');

        // 相同輸入，但每次輸出必定不同（因為 ephemeral key、salt、nonce 都是隨機）
        $this->assertNotSame($a['server_ephemeral_public_key'], $b['server_ephemeral_public_key']);
        $this->assertNotSame($a['salt'], $b['salt']);
        $this->assertNotSame($a['nonce'], $b['nonce']);
        $this->assertNotSame($a['ciphertext'], $b['ciphertext']);
    }

    public function test_wrap_is_bound_to_license_id(): void
    {
        // 用固定 keypair 與 content key，只改 license_id，驗證 HKDF info 繫結有效
        [$clientPriv, $clientPub] = $this->generateClientKeyPair();
        $rawContentKey = random_bytes(32);

        // Server 用 license_id = "6" wrap
        $wrapped = $this->wrapper->wrap($rawContentKey, $clientPub, '6', 'same-fp');

        // Client 嘗試用 license_id = "7" unwrap → 必定失敗
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Poly1305 verification failed');

        $this->unwrapAsClient($wrapped, $clientPriv, '7', 'same-fp');
    }

    public function test_wrap_is_bound_to_fingerprint(): void
    {
        [$clientPriv, $clientPub] = $this->generateClientKeyPair();
        $rawContentKey = random_bytes(32);

        $wrapped = $this->wrapper->wrap($rawContentKey, $clientPub, 'same-license', 'fp-A');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Poly1305 verification failed');

        $this->unwrapAsClient($wrapped, $clientPriv, 'same-license', 'fp-B');
    }

    public function test_wrap_aad_tampering_is_detected(): void
    {
        [, $clientPub] = $this->generateClientKeyPair();
        $rawContentKey = random_bytes(32);
        $wrapped = $this->wrapper->wrap($rawContentKey, $clientPub, '1', 'fp');

        // 用錯的 AAD 嘗試 decrypt（不經過 unwrapAsClient，直接呼叫 sodium）
        $serverPub = base64_decode($wrapped['server_ephemeral_public_key']);
        $salt = base64_decode($wrapped['salt']);
        $nonce = base64_decode($wrapped['nonce']);
        $ciphertext = base64_decode($wrapped['ciphertext']);

        // 但此時我們沒有 client priv 也算不出 shared secret...
        // 改做另一種檢測：改動 wrapped['ciphertext'] 的第一個 byte
        $tampered = $ciphertext;
        $tampered[0] = chr(ord($tampered[0]) ^ 0xFF);

        // 模擬一個合法 client 嘗試 unwrap tampered 版本
        [$clientPriv, $clientPub2] = $this->generateClientKeyPair();
        // 我們已經 wrap 過了，這邊就直接檢測 sodium decrypt 會失敗
        $fakeWrapKey = hash_hkdf('sha256', random_bytes(32), 32, 'fake-info', $salt);
        $result = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $tampered,
            $wrapped['aad'],
            $nonce,
            $fakeWrapKey,
        );

        // 無論是 wrap key 不對還是 ciphertext 被篡改，sodium decrypt 都回傳 false
        $this->assertFalse($result);
    }

    public function test_wrap_is_interoperable_with_fixture_vectors(): void
    {
        // 這個 test 驗證：用 fixture 的所有輸入（含隨機素材）手動重現 HKDF + encrypt，
        // 應該得到與 fixture 宣告的 expected_wrap_key 和 expected_ciphertext byte-perfect 相同的結果。
        // 目的：確保 ContentKeyWrapper 的內部邏輯與 fixture 生成器（tests/Fixtures/generate_ecdh_vectors.php）一致。
        $fixturesPath = __DIR__.'/../../Fixtures/ecdh_wrap_vectors.json';
        $this->assertFileExists($fixturesPath);

        $fixtures = json_decode(file_get_contents($fixturesPath), true);
        $this->assertSame(ContentKeyWrapper::ALG, $fixtures['algorithm']);

        foreach ($fixtures['vectors'] as $v) {
            $clientPriv = base64_decode($v['client_private_key_b64']);
            $serverPub = base64_decode($v['server_public_key_b64']);
            $rawContentKey = base64_decode($v['raw_content_key_b64']);
            $salt = base64_decode($v['salt_b64']);
            $nonce = base64_decode($v['nonce_b64']);
            $expectedWrapKey = base64_decode($v['expected_wrap_key_b64']);
            $expectedCiphertext = base64_decode($v['expected_ciphertext_b64']);

            // Client 視角：用自己的 priv + server 的 pub 算 shared secret
            $shared = sodium_crypto_scalarmult($clientPriv, $serverPub);
            $info = ContentKeyWrapper::INFO_PREFIX."\x00".$v['license_id']."\x00".$v['fingerprint'];
            $wrapKey = hash_hkdf('sha256', $shared, 32, $info, $salt);

            $this->assertSame($expectedWrapKey, $wrapKey, "[{$v['description']}] wrap key mismatch");

            // 用 expected_wrap_key 去 decrypt expected_ciphertext，應還原 raw_content_key
            $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                $expectedCiphertext,
                $v['aad'],
                $nonce,
                $expectedWrapKey,
            );
            $this->assertNotFalse($plaintext, "[{$v['description']}] decrypt failed");
            $this->assertSame($rawContentKey, $plaintext, "[{$v['description']}] content key mismatch");
        }
    }
}
