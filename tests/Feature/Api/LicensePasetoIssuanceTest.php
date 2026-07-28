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
 *   3. validate 端點簽發的驗證收據（validation_token）可驗章、不含
 *      wrapped_content_key，且 force_online_after 隨每次成功 validate 往後推。
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

    private function createActiveLicense(?LicenseScope $scope = null, bool $perpetual = false): array
    {
        $licenseKey = 'PASETO-'.bin2hex(random_bytes(8));
        $license = License::create([
            'key_hash' => License::hashKey($licenseKey),
            'status' => LicenseStatus::Active,
            'license_scope_id' => $scope?->id,
            'activated_at' => now(),
            'expires_at' => $perpetual ? null : now()->addDays(30),
            'max_usages' => 1,
            'meta' => [],
        ]);

        return [$licenseKey, $license];
    }

    /**
     * 走真實 activate 端點完成啟用，回傳 validate 測試所需的素材
     * （bearer token、signing public key、license 與 fingerprint）。
     *
     * @return array{token: string, signing_public_key: string, license_key: string, license: License, fingerprint: string}
     */
    private function activateThroughEndpoint(bool $perpetual = false): array
    {
        $cek = ContentEncryptionKey::create([
            'name' => 'validate-receipt-cek',
            'encrypted_key' => base64_encode(random_bytes(32)),
        ]);
        $scope = LicenseScope::create([
            'name' => 'Validate Receipt Product',
            'is_active' => true,
            'content_encryption_key_id' => $cek->id,
        ]);
        [$licenseKey, $license] = $this->createActiveLicense($scope, perpetual: $perpetual);

        $clientPub = sodium_crypto_box_publickey(sodium_crypto_box_keypair());
        $fingerprint = 'validate-receipt-fp';

        $response = $this->postJson('/api/licensing/v1/activate', [
            'license_key' => $licenseKey,
            'fingerprint' => $fingerprint,
            'client_ephemeral_public_key' => base64_encode($clientPub),
        ]);
        $response->assertStatus(200);
        $data = $response->json('data');

        return [
            'token' => $data['token'],
            'signing_public_key' => $data['public_key_bundle']['signing']['public_key'],
            'license_key' => $licenseKey,
            'license' => $license,
            'fingerprint' => $fingerprint,
        ];
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

    public function test_validate_endpoint_issues_verifiable_receipt_token_without_content_key(): void
    {
        $activation = $this->activateThroughEndpoint();
        $activateClaims = $this->parseClaims($activation['token'], $activation['signing_public_key']);

        // 模擬 App 三天後回報心跳：驗證收據的 force_online_after 必須比
        // activate token 的更晚（issue() 以簽發當下的 now 重算）
        $this->travel(3)->days();

        $response = $this->withToken($activation['token'])->postJson('/api/licensing/v1/validate', [
            'license_key' => $activation['license_key'],
            'fingerprint' => $activation['fingerprint'],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $validationToken = $response->json('data.validation_token');
        $this->assertIsString($validationToken);
        $this->assertStringStartsWith('v4.public.', $validationToken);

        $claims = $this->parseClaims($validationToken, $activation['signing_public_key']);

        // 驗證收據絕不得帶 wrapped_content_key（App 於 activate 時已取得 content key）
        $this->assertArrayNotHasKey('wrapped_content_key', $claims);
        $this->assertSame($activation['license']->id, $claims['license_id']);
        $this->assertSame($activation['fingerprint'], $claims['usage_fingerprint']);

        // HIGH #6 的核心保證：每次成功 validate 都把強制上線截止點往後推
        $this->assertGreaterThan(
            strtotime($activateClaims['force_online_after']),
            strtotime($claims['force_online_after']),
        );
    }

    public function test_validate_endpoint_issues_receipt_token_for_perpetual_license(): void
    {
        // 永久授權（expires_at = null）走 PERPETUAL_TOKEN_TTL_DAYS 分支——
        // 該常數定義在父類 LicenseController，若可見性退回 private，
        // ValidateController 的 self:: 存取會 fatal error，本測試會收到 500
        $activation = $this->activateThroughEndpoint(perpetual: true);

        $response = $this->withToken($activation['token'])->postJson('/api/licensing/v1/validate', [
            'license_key' => $activation['license_key'],
            'fingerprint' => $activation['fingerprint'],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $claims = $this->parseClaims($response->json('data.validation_token'), $activation['signing_public_key']);

        $this->assertArrayNotHasKey('wrapped_content_key', $claims);
        $this->assertArrayNotHasKey('license_expires_at', $claims);

        // TTL 應為 PERPETUAL_TOKEN_TTL_DAYS（36500 天），實質永不過期
        $this->assertGreaterThan(
            now()->addDays(36000)->getTimestamp(),
            strtotime($claims['exp']),
        );
    }

    /**
     * 迴歸防護：明文授權碼絕不得出現在任何 API 回應中。
     *
     * 業主決策：log / Nightwatch 看得到可接受，但**不可回到 client**。
     * 目前成立的原因是 activate/validate 全走手工 formatLicense()（無明文
     * 欄位）、token claims 只帶 license_key_hash、422 只回欄位名不回值。
     * 未來若升級 vendor 或改動 formatLicense()／buildLicenseResponse()／
     * 錯誤處理而不慎把 license_key 帶進回應，本測試會立即失敗。
     */
    public function test_api_responses_never_contain_plaintext_license_key(): void
    {
        $activation = $this->activateThroughEndpoint();
        $licenseKey = $activation['license_key'];

        $validate = $this->withToken($activation['token'])
            ->postJson('/api/licensing/v1/validate', [
                'license_key' => $licenseKey,
                'fingerprint' => $activation['fingerprint'],
            ]);
        $validate->assertStatus(200);
        $this->assertStringNotContainsString($licenseKey, $validate->getContent());

        // 422 驗證錯誤不得回顯輸入值（只應回欄位名）
        $invalid = $this->postJson('/api/licensing/v1/activate', [
            'license_key' => $licenseKey,
            'fingerprint' => 'regression-fp',
            // 故意漏 client_ephemeral_public_key
        ]);
        $invalid->assertStatus(422);
        $this->assertStringNotContainsString($licenseKey, $invalid->getContent());

        // 404 未知授權碼不得回顯輸入值
        $unknownKey = 'NONEXISTENT-KEY-ABC123';
        $unknown = $this->postJson('/api/licensing/v1/activate', [
            'license_key' => $unknownKey,
            'fingerprint' => 'regression-fp',
            'client_ephemeral_public_key' => base64_encode(
                sodium_crypto_box_publickey(sodium_crypto_box_keypair()),
            ),
        ]);
        $unknown->assertStatus(404);
        $this->assertStringNotContainsString($unknownKey, $unknown->getContent());
    }
}
