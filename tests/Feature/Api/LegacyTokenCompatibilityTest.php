<?php

/**
 * 升級前簽發的 token 在升級後仍必須可用。
 *
 * 為什麼需要這個檔：LicensePasetoIssuanceTest 守的是「同一版程式碼簽出的 token
 * 能被同一版驗過」。若 vendor 或 paragonie/paseto 同時改了簽發與驗章（footer 格式、
 * claim 型別、私鑰加密參數），那份往返測試照樣全綠，但正式站上**已經發出去**的
 * token 與 DB 裡**既存**的加密私鑰會全部失效——永久授權 token TTL 為 100 年，
 * 升級時不會重新簽發，等於所有已出貨的 App 同時無法認證。
 *
 * 做法：tests/Fixtures/legacy_token_fixture.json 由
 * tests/Fixtures/generate_legacy_token_fixture.php 以升級前的程式碼產生，內含
 * licensing_keys 原始 row（含 passphrase 加密的私鑰）與當時簽出的 token。
 * 本檔把那些 row 原樣放回 DB、模擬「升級後的程式碼面對升級前的正式站資料」，
 * **不 mock 任何簽章/驗章**。
 *
 * ⚠️ 本檔轉紅時，正確的處置是修相容性，而不是重新產生 fixture。
 */

use App\Models\License;
use App\Models\LicenseUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use LucaLongo\Licensing\Contracts\TokenVerifier;
use LucaLongo\Licensing\Enums\LicenseStatus;
use LucaLongo\Licensing\Enums\UsageStatus;
use LucaLongo\Licensing\Models\LicensingKey;
use ParagonIE\Paseto\Keys\AsymmetricPublicKey;
use ParagonIE\Paseto\Parser;
use ParagonIE\Paseto\Protocol\Version4;

uses(RefreshDatabase::class);

$fixture = json_decode(
    file_get_contents(__DIR__.'/../../Fixtures/legacy_token_fixture.json'),
    true,
    flags: JSON_THROW_ON_ERROR,
);

$legacySigningKey = collect($fixture['licensing_keys'])->firstWhere('type', 'signing');

beforeEach(function () use ($fixture): void {
    Cache::flush();

    config([
        'licensing.crypto.keystore.driver' => 'database',
        'licensing.crypto.keystore.passphrase' => $fixture['keystore_passphrase'],
        'licensing.offline_token.issuer' => $fixture['issuer'],
    ]);
    // passphrase 以 static 快取，會跨測試殘留；不清掉就會拿別的測試的 passphrase
    // 解 fixture 私鑰而失敗。
    LicensingKey::forgetCachedPassphrase();

    // 原樣放回升級前的 row，而非重新產生金鑰——要驗的正是「既存資料」的相容性。
    DB::table('licensing_keys')->insert($fixture['licensing_keys']);

    // id 必須與 token 的 license_id claim 一致：內容端點的 EnsureLicenseUsable 以它回查授權。
    $license = (new License)->forceFill([
        'id' => $fixture['license']['id'],
        'key_hash' => License::hashKey($fixture['license']['license_key']),
        'status' => LicenseStatus::Active,
        'activated_at' => now()->subYear(),
        'expires_at' => null,
        'max_usages' => 1,
        'meta' => [],
    ]);
    $license->save();

    LicenseUsage::create([
        'license_id' => $license->getKey(),
        'usage_fingerprint' => $fixture['license']['fingerprint'],
        'status' => UsageStatus::Active,
        'registered_at' => now()->subYear(),
        'last_seen_at' => now()->subYear(),
        'client_type' => 'mobile',
        'meta' => [],
    ]);
});

afterEach(function (): void {
    LicensingKey::forgetCachedPassphrase();
});

/**
 * App 本地保存的 token 有兩種來源：activate 簽發的原始 token，以及 validate
 * 回傳後替換上去的驗證收據。兩種都必須在升級後持續有效。
 */
dataset('legacy tokens', ['activate_token', 'validation_token']);

test('legacy tokens still pass signature verification', function (string $tokenName) use ($fixture, $legacySigningKey): void {
    $claims = app(TokenVerifier::class)->verify($fixture[$tokenName]);

    $this->assertSame($fixture['license']['id'], $claims['license_id']);
    $this->assertSame($fixture['license']['fingerprint'], $claims['usage_fingerprint']);
    $this->assertSame($legacySigningKey['kid'], $claims['footer']['kid']);
})->with('legacy tokens');

test('the legacy activate token still carries its wrapped content key', function () use ($fixture): void {
    $claims = app(TokenVerifier::class)->verify($fixture['activate_token']);

    $this->assertIsArray($claims['wrapped_content_key']);
    $this->assertNotEmpty($claims['wrapped_content_key']);
});

test('an app holding a legacy token can still validate online', function (string $tokenName) use ($fixture, $legacySigningKey): void {
    $response = $this->withToken($fixture[$tokenName])->postJson('/api/licensing/v1/validate', [
        'license_key' => $fixture['license']['license_key'],
        'fingerprint' => $fixture['license']['fingerprint'],
    ]);

    $response->assertOk();

    // 新收據必須由既存 row 的私鑰簽出：證明 DB 裡加密存放的私鑰在新版仍解得開。
    $publicKey = new AsymmetricPublicKey(base64_decode($legacySigningKey['public_key']), new Version4);
    $claims = Parser::getPublic($publicKey)
        ->setNonExpiring(true)
        ->parse($response->json('data.validation_token'))
        ->getClaims();

    $this->assertSame($fixture['license']['id'], $claims['license_id']);
})->with('legacy tokens');

test('an app holding a legacy token can still load content', function (string $tokenName) use ($fixture): void {
    foreach (['/api/v1/wordwalls', '/api/v1/wordwall-categories'] as $url) {
        $this->withToken($fixture[$tokenName])->getJson($url)->assertOk();
    }
})->with('legacy tokens');
