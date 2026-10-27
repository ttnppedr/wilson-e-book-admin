<?php

/**
 * 已出貨 App（wilson-e-book-english）實際依賴的 API 契約。
 *
 * 其餘 API 測試守的是後端自身的行為；本檔守的是「App 會怎麼解讀這些回應」——
 * 每條斷言都對應 App 端一個會拋例外、或直接清除本機金鑰與授權的解析點
 * （lib/core/license/、lib/features/game/wordwall/）。App 的 Dart 解析是嚴格型別：
 * 欄位缺漏或型別改變不會被忽略，而是讓啟用失敗或讓已啟用的裝置被清空。
 * 本檔轉紅代表已出貨的 App 會在該處失敗，正確處置是修後端相容性，不是改斷言。
 *
 * 與其他 API 測試的兩個刻意差異：
 *   1. 全程使用真實 root/signing key 簽發與驗章，不 mock verifier。
 *   2. 授權端點的請求**不帶 Accept header**，比照 App（dart:io 只送 Content-Type）。
 *      其他測試的 postJson() 會自動帶 Accept: application/json，守不到這點。
 */

use App\Models\ContentEncryptionKey;
use App\Models\License;
use App\Models\LicenseScope;
use App\Models\Wordwall;
use App\Models\WordwallCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use LucaLongo\Licensing\Enums\LicenseStatus;
use LucaLongo\Licensing\Models\LicensingKey;
use ParagonIE\Paseto\Keys\AsymmetricPublicKey;
use ParagonIE\Paseto\Parser;
use ParagonIE\Paseto\Protocol\Version4;
use Tests\Concerns\CreatesLicensingKeys;

uses(CreatesLicensingKeys::class, RefreshDatabase::class);

/** App 以帶時區的 ISO 8601 解析日期；不帶時區會被當成裝置本地時間。 */
$isoWithOffset = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?([+-]\d{2}:\d{2}|Z)$/';

/** App 的 Dart base64.decode 要求補齊 padding（長度為 4 的倍數）。 */
$paddedBase64 = '/^(?:[A-Za-z0-9+\/]{4})*(?:[A-Za-z0-9+\/]{2}==|[A-Za-z0-9+\/]{3}=)?$/';

beforeEach(function (): void {
    Cache::flush();
    $this->createLicensingKeys('app-contract-test-passphrase');

    $this->rawContentKey = random_bytes(32);
    $cek = ContentEncryptionKey::create([
        'name' => 'app-contract-cek',
        'encrypted_key' => base64_encode($this->rawContentKey),
    ]);
    $scope = LicenseScope::create([
        'name' => 'App Contract Product',
        'is_active' => true,
        'content_encryption_key_id' => $cek->id,
    ]);

    // App 送出的授權碼：去掉破折號後 20 碼 [A-Z0-9]。
    $this->licenseKey = 'APPCONTRACTTEST00001';
    $this->license = License::create([
        'key_hash' => License::hashKey($this->licenseKey),
        'status' => LicenseStatus::Active,
        'license_scope_id' => $scope->id,
        'activated_at' => now(),
        'expires_at' => now()->addDays(30),
        'max_usages' => 1,
        'meta' => [],
    ]);
});

/**
 * 比照 App 的 dart:io 請求：只帶 Content-Type，不帶 Accept。
 * Symfony 測試請求預設會補一個瀏覽器式 Accept，故以空值覆寫。
 */
$postAsApp = function (string $uri, array $payload, ?string $bearer = null): TestResponse {
    $server = [
        'CONTENT_TYPE' => 'application/json; charset=utf-8',
        'HTTP_ACCEPT' => '',
    ];
    if ($bearer !== null) {
        $server['HTTP_AUTHORIZATION'] = 'Bearer '.$bearer;
    }

    return $this->call('POST', $uri, [], [], [], $server, json_encode($payload, JSON_THROW_ON_ERROR));
};

/**
 * 以一把新的 X25519 ephemeral key 走 activate（App 每次嘗試都重新產生）。
 * fingerprint 比照 App：未經雜湊的 ANDROID_ID。
 *
 * @return array{0: TestResponse, 1: string} 回應與 client 私鑰（解 content key 用）
 */
$activateAsApp = function (string $fingerprint) use ($postAsApp): array {
    $keyPair = sodium_crypto_box_keypair();

    $response = $postAsApp->call($this, '/api/licensing/v1/activate', [
        'license_key' => $this->licenseKey,
        'fingerprint' => $fingerprint,
        'client_ephemeral_public_key' => base64_encode(sodium_crypto_box_publickey($keyPair)),
    ]);

    return [$response, sodium_crypto_box_secretkey($keyPair)];
};

$validateAsApp = function (string $fingerprint, string $bearer) use ($postAsApp): TestResponse {
    return $postAsApp->call($this, '/api/licensing/v1/validate', [
        'license_key' => $this->licenseKey,
        'fingerprint' => $fingerprint,
    ], $bearer);
};

/**
 * @return array<string, mixed>
 */
$parseClaims = function (string $token, string $signingPublicKeyB64): array {
    $publicKey = new AsymmetricPublicKey(base64_decode($signingPublicKeyB64), new Version4);

    return Parser::getPublic($publicKey)
        ->setNonExpiring(true)
        ->parse($token)
        ->getClaims();
};

/**
 * activate 與 validate 共用的 license / usage 區塊（App 端 LicenseInfo / UsageInfo）。
 * 以 json_decode(..., false) 檢查，才分得出 JSON array 與 object。
 */
$assertLicenseAndUsageShape = function (TestResponse $response, string $fingerprint): void {
    $data = json_decode($response->getContent(), false, flags: JSON_THROW_ON_ERROR)->data;

    $this->assertIsString($data->license->id, 'license.id 是 vendor uid 字串');
    $this->assertIsString($data->license->status);
    $this->assertIsInt($data->license->max_usages);
    $this->assertIsInt($data->license->active_usages);
    $this->assertIsInt($data->license->available_seats);
    $this->assertIsArray($data->license->features, 'features 必須是 JSON array，null 或 object 都會讓 App 拋例外');
    $this->assertIsArray($data->license->entitlements, 'entitlements 必須是 JSON array，null 或 object 都會讓 App 拋例外');

    $this->assertIsInt($data->usage->id);
    $this->assertSame($fingerprint, $data->usage->fingerprint);
    $this->assertIsString($data->usage->status);
};

test('activate returns exactly the shape the app parses', function () use ($activateAsApp, $assertLicenseAndUsageShape): void {
    [$response] = $activateAsApp->call($this, 'a1b2c3d4e5f60718');

    // App 只接受 200；連 201 都會被當成錯誤。
    $response->assertStatus(200);
    $assertLicenseAndUsageShape->call($this, $response, 'a1b2c3d4e5f60718');

    $data = $response->json('data');
    $this->assertIsString($data['token']);
    $this->assertIsArray($data['public_key_bundle'], 'bundle 為 null 時 App 顯示「伺服器未回傳授權憑證」');

    foreach (['signing.kid', 'signing.public_key', 'signing.certificate', 'root.kid', 'root.public_key'] as $path) {
        $this->assertIsString(data_get($data['public_key_bundle'], $path), "public_key_bundle.{$path} 必須是字串");
    }
});

test('token claims carry the types the app enforces', function () use ($activateAsApp, $parseClaims, $isoWithOffset): void {
    $fingerprint = 'a1b2c3d4e5f60718';
    [$response] = $activateAsApp->call($this, $fingerprint);
    $claims = $parseClaims($response->json('data.token'), $response->json('data.public_key_bundle.signing.public_key'));

    $this->assertIsString($claims['sub']);
    // App 以字面值比對 iss（lib/core/license/license_constants.dart），不讀設定。
    $this->assertSame('wilson-ebook-admin', $claims['iss']);
    $this->assertIsString($claims['kid']);
    $this->assertSame($this->license->id, $claims['license_id'], 'license_id 必須是 int');
    $this->assertIsString($claims['license_key_hash']);
    // 原樣回寫 ANDROID_ID：任何雜湊或大小寫轉換都會讓 App 比對失敗。
    $this->assertSame($fingerprint, $claims['usage_fingerprint']);
    // 大小寫敏感：非 'active' 一律被 App 視為不可用並清除授權。
    $this->assertSame('active', $claims['status']);
    $this->assertIsInt($claims['max_usages']);

    // App 宣告為 String?：非 null 的 int 會拋 TypeError。
    foreach (['licensable_type', 'licensable_id'] as $claim) {
        $this->assertTrue(
            $claims[$claim] === null || is_string($claims[$claim]),
            "{$claim} 必須是 null 或字串",
        );
    }

    foreach (['iat', 'nbf', 'exp', 'force_online_after', 'license_expires_at'] as $claim) {
        $this->assertMatchesRegularExpression($isoWithOffset, $claims[$claim], "{$claim} 必須是帶時區的 ISO 8601");
    }

    // App 拒收「未來才生效」的 token（容許 60 秒時鐘偏差）。
    $this->assertLessThanOrEqual(now()->addMinute()->timestamp, strtotime($claims['nbf']));
    $this->assertLessThanOrEqual(now()->addMinute()->timestamp, strtotime($claims['iat']));

    // App 要求 iat ≤ force_online_after ≤ iat + 10000 天，超出即視為竄改。
    $issuedAt = strtotime($claims['iat']);
    $forceOnlineAfter = strtotime($claims['force_online_after']);
    $this->assertGreaterThanOrEqual($issuedAt, $forceOnlineAfter);
    $this->assertLessThanOrEqual($issuedAt + 10000 * 86400, $forceOnlineAfter);
});

test('token footer carries a root-signed certificate the app verifies offline', function () use ($activateAsApp, $parseClaims, $paddedBase64): void {
    [$response] = $activateAsApp->call($this, 'a1b2c3d4e5f60718');
    $token = $response->json('data.token');
    $bundle = $response->json('data.public_key_bundle');
    $claims = $parseClaims($token, $bundle['signing']['public_key']);

    // App 重啟後只讀 footer 的 chain.signing.certificate（字串，不是物件）。
    $footer = json_decode(Parser::extractFooter($token), true, flags: JSON_THROW_ON_ERROR);
    $certificate = $footer['chain']['signing']['certificate'];
    $this->assertIsString($certificate);
    // 啟用當下讀 bundle、重啟後讀 footer：兩處必須是同一張憑證。
    $this->assertSame($bundle['signing']['certificate'], $certificate);

    $decoded = json_decode($certificate, true, flags: JSON_THROW_ON_ERROR);
    $this->assertSame(['certificate', 'signature'], array_keys($decoded));
    $inner = $decoded['certificate'];

    $rootKey = LicensingKey::findActiveRoot();
    // App 內建 root 公鑰與 kid；issuer_kid 不符即拒收。
    $this->assertSame($rootKey->kid, $inner['issuer_kid']);
    $this->assertSame($claims['kid'], $inner['kid']);
    $this->assertMatchesRegularExpression($paddedBase64, $inner['public_key'], '憑證內 public_key 由 App 以嚴格 base64 解碼');
    $this->assertSame(32, strlen(base64_decode($inner['public_key'], true)));
    $this->assertSame($bundle['signing']['public_key'], $inner['public_key']);
    $this->assertIsString($inner['valid_from']);

    // App 驗的是「字串中 certificate 值的原始 bytes」，含 PHP 的 \/ 跳脫。
    $signedBytes = json_encode($inner);
    $this->assertStringContainsString('"certificate":'.$signedBytes, $certificate);
    $this->assertTrue(sodium_crypto_sign_verify_detached(
        base64_decode($decoded['signature']),
        $signedBytes,
        base64_decode($rootKey->getPublicKey()),
    ), '憑證必須能以 root 公鑰驗章');
});

test('wrapped content key uses the exact encoding the app decodes', function () use ($activateAsApp, $parseClaims, $paddedBase64): void {
    $fingerprint = 'a1b2c3d4e5f60718';
    [$response, $clientSecretKey] = $activateAsApp->call($this, $fingerprint);
    $claims = $parseClaims($response->json('data.token'), $response->json('data.public_key_bundle.signing.public_key'));
    $wrapped = $claims['wrapped_content_key'];

    // 以下常數在 App 端是寫死的字面值，後端改了常數 App 不會跟著改。
    $this->assertSame('X25519+HKDF-SHA256+XChaCha20-Poly1305', $wrapped['alg']);

    foreach (['server_ephemeral_public_key', 'salt', 'nonce', 'ciphertext'] as $field) {
        $this->assertMatchesRegularExpression($paddedBase64, $wrapped[$field], "{$field} 必須是補齊 padding 的 base64");
    }
    $serverPublicKey = base64_decode($wrapped['server_ephemeral_public_key']);
    $nonce = base64_decode($wrapped['nonce']);
    $this->assertSame(32, strlen($serverPublicKey));
    $this->assertSame(24, strlen($nonce));

    // 比照 App 解包：AAD 用寫死的常數（不讀 claim 的 aad），info 帶十進位 license_id。
    $sharedSecret = sodium_crypto_scalarmult($clientSecretKey, $serverPublicKey);
    $info = 'wilson-content-key-wrap-v1'."\x00".$claims['license_id']."\x00".$fingerprint;
    $wrapKey = hash_hkdf('sha256', $sharedSecret, 32, $info, base64_decode($wrapped['salt']));
    $contentKey = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
        base64_decode($wrapped['ciphertext']),
        'wilson-content-key-wrap-v1',
        $nonce,
        $wrapKey,
    );

    $this->assertSame(bin2hex($this->rawContentKey), bin2hex((string) $contentKey));
});

test('reactivating on the same device succeeds without consuming another seat', function () use ($activateAsApp): void {
    // App 每次清除本機資料後，使用者都要在同一台裝置重新輸入授權碼（max_usages = 1）。
    [$first] = $activateAsApp->call($this, 'a1b2c3d4e5f60718');
    [$second] = $activateAsApp->call($this, 'a1b2c3d4e5f60718');

    $first->assertStatus(200);
    $second->assertStatus(200);
    $this->assertSame($first->json('data.usage.id'), $second->json('data.usage.id'));
    $this->assertSame(1, $second->json('data.license.active_usages'));
    $this->assertSame(0, $second->json('data.license.available_seats'));
});

test('a second device on a full license gets USAGE_LIMIT_REACHED', function () use ($activateAsApp): void {
    [$first] = $activateAsApp->call($this, 'a1b2c3d4e5f60718');
    [$second] = $activateAsApp->call($this, 'ffeeddccbbaa0099');

    $first->assertStatus(200);
    // App 依此碼顯示「此授權碼已綁定其他裝置」。vendor 以例外訊息是否含 'limit'
    // 判定此碼，訊息措辭一改就會悄悄變成 400 USAGE_REGISTRATION_FAILED。
    $second->assertStatus(409);
    $second->assertJsonPath('error.code', 'USAGE_LIMIT_REACHED');
});

test('validate returns exactly the shape the app parses', function () use ($activateAsApp, $validateAsApp, $assertLicenseAndUsageShape, $parseClaims): void {
    $fingerprint = 'a1b2c3d4e5f60718';
    [$activation] = $activateAsApp->call($this, $fingerprint);
    $signingPublicKey = $activation->json('data.public_key_bundle.signing.public_key');

    $response = $validateAsApp->call($this, $fingerprint, $activation->json('data.token'));

    $response->assertStatus(200);
    $assertLicenseAndUsageShape->call($this, $response, $fingerprint);
    $validationToken = $response->json('data.validation_token');
    $this->assertIsString($validationToken);
    $this->assertSame('active', $parseClaims($validationToken, $signingPublicKey)['status']);

    // App 會以 validation_token 取代本機 token，下次 validate 就帶它當 bearer。
    $validateAsApp->call($this, $fingerprint, $validationToken)->assertStatus(200);
});

test('wordwall categories return exactly the shape the app parses', function () use ($activateAsApp): void {
    [$activation] = $activateAsApp->call($this, 'a1b2c3d4e5f60718');
    $category = WordwallCategory::factory()->create();
    Wordwall::factory()->create(['wordwall_category_id' => $category->getKey()]);

    $headers = [
        'Authorization' => 'Bearer '.$activation->json('data.token'),
        'Accept' => 'application/json',
    ];
    $response = $this->withHeaders($headers)->get('/api/v1/wordwall-categories');

    $response->assertStatus(200);
    $data = json_decode($response->getContent(), false, flags: JSON_THROW_ON_ERROR)->data;
    $this->assertIsArray($data);
    foreach ($data as $item) {
        $this->assertIsInt($item->id);
        $this->assertIsString($item->name);
        $this->assertTrue($item->image_url === null || is_string($item->image_url));
        $this->assertIsInt($item->sort);
        $this->assertIsArray($item->wordwalls);
        foreach ($item->wordwalls as $wordwall) {
            $this->assertIsInt($wordwall->sort);
            $this->assertIsString($wordwall->resource_url);
        }
    }

    // App 把上次 200 的 etag header 原樣放進 If-None-Match。
    $etag = $response->headers->get('ETag');
    $this->assertNotNull($etag);
    $this->withHeaders([...$headers, 'If-None-Match' => $etag])
        ->get('/api/v1/wordwall-categories')
        ->assertStatus(304);
});

test('error responses stay a JSON envelope even without an Accept header', function (string $scenario, int $status, string $code) use ($postAsApp, $activateAsApp, $validateAsApp): void {
    $fingerprint = 'a1b2c3d4e5f60718';

    $response = match ($scenario) {
        'unknown license key' => $postAsApp->call($this, '/api/licensing/v1/activate', [
            'license_key' => 'NOSUCHLICENSEKEY0000',
            'fingerprint' => $fingerprint,
            'client_ephemeral_public_key' => base64_encode(random_bytes(32)),
        ]),
        'malformed payload' => $postAsApp->call($this, '/api/licensing/v1/activate', [
            'license_key' => $this->licenseKey,
        ]),
        'missing bearer token' => $postAsApp->call($this, '/api/licensing/v1/validate', [
            'license_key' => $this->licenseKey,
            'fingerprint' => $fingerprint,
        ]),
        // App 對 validate 的任何 JSON 404 一律清除本機授權，只能出現在授權真的不存在時。
        'unknown license on validate' => (function () use ($postAsApp, $activateAsApp, $fingerprint): TestResponse {
            [$activation] = $activateAsApp->call($this, $fingerprint);

            return $postAsApp->call($this, '/api/licensing/v1/validate', [
                'license_key' => 'NOSUCHLICENSEKEY0000',
                'fingerprint' => $fingerprint,
            ], $activation->json('data.token'));
        })(),
        'rate limited' => (function () use ($activateAsApp, $fingerprint): TestResponse {
            config(['licensing.rate_limit.activate_per_minute' => 1]);
            $activateAsApp->call($this, $fingerprint);

            return $activateAsApp->call($this, $fingerprint)[0];
        })(),
        default => (function () use ($scenario, $activateAsApp, $validateAsApp, $fingerprint): TestResponse {
            [$activation] = $activateAsApp->call($this, $fingerprint);

            match ($scenario) {
                'cancelled license' => $this->license->status = LicenseStatus::Cancelled,
                'suspended license' => $this->license->status = LicenseStatus::Suspended,
                'expired license' => $this->license->expires_at = now()->subMinute(),
            };
            $this->license->save();

            return $validateAsApp->call($this, $fingerprint, $activation->json('data.token'));
        })(),
    };

    // App 先 jsonDecode 再看狀態碼：非 JSON 物件的 body 會變成 FormatException，
    // 不會進入任何依錯誤碼分流的處理。
    $response->assertStatus($status);
    $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
    $response->assertJsonPath('success', false);
    $response->assertJsonPath('error.code', $code);
    $this->assertIsString($response->json('error.message'));
})->with([
    'unknown license key' => ['unknown license key', 404, 'INVALID_KEY'],
    'malformed payload' => ['malformed payload', 422, 'VALIDATION_FAILED'],
    'missing bearer token' => ['missing bearer token', 401, 'INVALID_TOKEN'],
    'unknown license on validate' => ['unknown license on validate', 404, 'INVALID_KEY'],
    'rate limited' => ['rate limited', 429, 'RATE_LIMITED'],
    'cancelled license' => ['cancelled license', 410, 'CANCELLED_LICENSE'],
    'suspended license' => ['suspended license', 423, 'SUSPENDED_LICENSE'],
    'expired license' => ['expired license', 410, 'EXPIRED_LICENSE'],
]);
