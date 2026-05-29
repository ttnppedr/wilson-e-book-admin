<?php

namespace Tests\Feature\Api;

use App\Models\ContentEncryptionKey;
use App\Models\License;
use App\Models\LicenseScope;
use App\Services\ContentKeyWrapper;
use App\Services\WilsonPasetoTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use LucaLongo\Licensing\Enums\LicenseStatus;
use LucaLongo\Licensing\Models\LicenseUsage;
use LucaLongo\Licensing\Models\LicensingKey;
use ParagonIE\Paseto\Keys\AsymmetricPublicKey;
use ParagonIE\Paseto\Parser;
use ParagonIE\Paseto\Protocol\Version4;
use Tests\TestCase;

/**
 * 覆蓋「真實 PASETO 簽發路徑」的測試。
 *
 * 為什麼需要這個檔：其餘 API 測試（LicenseValidateTokenTest、WordwallListTest）以
 * `MocksLicenseTokenVerifier` mock 掉 TokenVerifier，而 LicenseActivateEcdhTest 的
 * happy path 又刻意停在 issueToken 之前。因此「用真實 signing key 簽出 PASETO token、
 * 再以 signing public key 驗章」這條路徑從未被自動化測試執行——正是升級
 * paragonie/paseto（3.0→3.5）與 laravel-licensing（2.0→2.1）最該守護的核心。
 *
 * 本檔以真實 root/signing key（database keystore，RefreshDatabase 逐測試清空）建立
 * 完整基礎設施，**不 mock 任何簽章/驗章**，守護兩件事：
 *   1. WilsonPasetoTokenService::issue() 簽出的 token 能被 signing public key 驗章，
 *      且 extra_claims（wrapped_content_key）完整保留。
 *   2. activate 端點端對端：回傳的 token 可驗章、wrapped content key 可 unwrap 還原。
 *
 * 對齊守護：vendor 2.0.1 起 signing key 改用 seed-derived `buildSecretKey()` 以通過
 * paseto v4 misuse-resistance。若 issue() 退回舊的 `new AsymmetricSecretKey(...)` 寫法，
 * 在 paseto 3.5 下特定金鑰會簽章失敗，本測試會紅。
 */
class LicensePasetoIssuanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'licensing.crypto.keystore.driver' => 'database',
            'licensing.crypto.keystore.passphrase' => 'paseto-issuance-test-passphrase',
            'licensing.publishing.public_bundle_path' => storage_path('framework/testing/licensing-public-bundle.json'),
        ]);

        $this->assertSame(0, Artisan::call('licensing:keys:make-root'), 'make-root should succeed');
        $this->assertSame(0, Artisan::call('licensing:keys:issue-signing', ['--days' => 365]), 'issue-signing should succeed');
    }

    private function activeSigningKey(): LicensingKey
    {
        $signing = LicensingKey::findActiveSigning();
        $this->assertNotNull($signing, 'active signing key should exist after setUp');

        return $signing;
    }

    /**
     * 用指定的 signing public key（base64）真實驗章並回傳 claims。
     *
     * @return array<string, mixed>
     */
    private function parseClaims(string $token, string $signingPublicKeyB64): array
    {
        $publicKey = new AsymmetricPublicKey(base64_decode($signingPublicKeyB64), new Version4);

        return Parser::getPublic($publicKey)
            ->setNonExpiring(true)
            ->parse($token)
            ->getClaims();
    }

    private function createActiveLicense(?LicenseScope $scope = null): array
    {
        $licenseKey = 'PASETO-'.bin2hex(random_bytes(8));
        $license = License::create([
            'key_hash' => License::hashKey($licenseKey),
            'status' => LicenseStatus::Active,
            'license_scope_id' => $scope?->id,
            'activated_at' => now(),
            'expires_at' => now()->addDays(30),
            'max_usages' => 1,
            'meta' => [],
        ]);

        return [$licenseKey, $license];
    }

    public function test_issue_produces_token_verifiable_by_signing_public_key(): void
    {
        [, $license] = $this->createActiveLicense();
        $usage = new LicenseUsage(['usage_fingerprint' => 'paseto-fp']);

        $token = (new WilsonPasetoTokenService)->issue($license, $usage, [
            'ttl_days' => 7,
            'extra_claims' => [
                'wrapped_content_key' => ['alg' => ContentKeyWrapper::ALG],
            ],
        ]);

        $this->assertStringStartsWith('v4.public.', $token);

        $signing = $this->activeSigningKey();
        $claims = $this->parseClaims($token, $signing->getPublicKey());

        $this->assertSame($license->id, $claims['license_id']);
        $this->assertSame('paseto-fp', $claims['usage_fingerprint']);
        $this->assertSame($license->key_hash, $claims['license_key_hash']);
        $this->assertSame($signing->kid, $claims['kid']);
        $this->assertSame(LicenseStatus::Active->value, $claims['status']);

        // extra_claims 必須原樣保留（由 Ed25519 簽章保護）
        $this->assertSame(ContentKeyWrapper::ALG, $claims['wrapped_content_key']['alg']);
    }

    public function test_issue_rejects_extra_claims_overriding_reserved_claims(): void
    {
        [, $license] = $this->createActiveLicense();
        $usage = new LicenseUsage(['usage_fingerprint' => 'paseto-fp']);

        $this->expectException(\RuntimeException::class);

        (new WilsonPasetoTokenService)->issue($license, $usage, [
            'extra_claims' => ['license_id' => 'tampered'],
        ]);
    }

    public function test_token_signed_with_one_signing_key_fails_under_a_rotated_key(): void
    {
        [, $license] = $this->createActiveLicense();
        $usage = new LicenseUsage(['usage_fingerprint' => 'paseto-fp']);

        $token = (new WilsonPasetoTokenService)->issue($license, $usage, ['ttl_days' => 7]);
        $originalPublicKey = $this->activeSigningKey()->getPublicKey();

        // 輪替 signing key：撤銷舊的、簽發新的，舊 token 不應再被新 public key 驗章通過
        $this->activeSigningKey()->revoke('test-rotation');
        $this->assertSame(0, Artisan::call('licensing:keys:issue-signing', ['--days' => 365]));
        $rotatedPublicKey = $this->activeSigningKey()->getPublicKey();
        $this->assertNotSame($originalPublicKey, $rotatedPublicKey);

        $this->expectException(\Throwable::class);
        $this->parseClaims($token, $rotatedPublicKey);
    }

    public function test_activate_endpoint_issues_real_token_and_wraps_content_key(): void
    {
        $rawContentKey = random_bytes(32);
        $cek = ContentEncryptionKey::create([
            'name' => 'paseto-test-cek',
            'encrypted_key' => base64_encode($rawContentKey),
        ]);
        $scope = LicenseScope::create([
            'name' => 'PASETO Test Product',
            'is_active' => true,
            'content_encryption_key_id' => $cek->id,
        ]);
        [$licenseKey, $license] = $this->createActiveLicense($scope);

        $clientKeyPair = sodium_crypto_box_keypair();
        $clientPriv = sodium_crypto_box_secretkey($clientKeyPair);
        $clientPub = sodium_crypto_box_publickey($clientKeyPair);
        $fingerprint = 'e2e-paseto-fp';

        $response = $this->postJson('/api/licensing/v1/activate', [
            'license_key' => $licenseKey,
            'fingerprint' => $fingerprint,
            'client_ephemeral_public_key' => base64_encode($clientPub),
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $data = $response->json('data');
        // 頂層回應絕不得直接帶明文 content key
        $this->assertArrayNotHasKey('content_key', $data);
        $this->assertArrayHasKey('token', $data);

        // 用回傳 bundle 的 signing public key 真實驗章（無 mock）
        $signingPub = $data['public_key_bundle']['signing']['public_key'];
        $claims = $this->parseClaims($data['token'], $signingPub);

        $wrapped = $claims['wrapped_content_key'];
        $this->assertSame(ContentKeyWrapper::ALG, $wrapped['alg']);

        // 以 client private key 解出 content key，須與 DB raw 值 byte-perfect 相符
        $shared = sodium_crypto_scalarmult(
            $clientPriv,
            base64_decode($wrapped['server_ephemeral_public_key'])
        );
        $info = ContentKeyWrapper::INFO_PREFIX."\x00".$claims['license_id']."\x00".$fingerprint;
        $wrapKey = hash_hkdf('sha256', $shared, 32, $info, base64_decode($wrapped['salt']));
        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            base64_decode($wrapped['ciphertext']),
            $wrapped['aad'],
            base64_decode($wrapped['nonce']),
            $wrapKey
        );

        $this->assertNotFalse($plaintext, 'content key unwrap should succeed');
        $this->assertSame(bin2hex($rawContentKey), bin2hex($plaintext));
    }
}
