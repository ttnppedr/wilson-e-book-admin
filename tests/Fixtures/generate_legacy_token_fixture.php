<?php

declare(strict_types=1);

// ============================================================================
// 既有 PASETO token 向後相容 fixture 產生器
// ============================================================================
//
// 用途：以「當下這版」程式碼走真實 activate / validate 端點簽出 token，連同簽發它
// 的 licensing_keys 原始 DB row（含 passphrase 加密的私鑰）一起凍結成 fixture，供
// tests/Feature/Api/LegacyTokenCompatibilityTest.php 在**升級後**的程式碼上重播：
//
//   1. 已發出去的 token 仍能通過 VerifyBearerToken（validate 與 v1/* 內容端點）
//   2. 正式站 DB 裡既存的加密私鑰仍能解密、繼續簽 token
//
// App 端持有的永久授權 token TTL 為 100 年，升級時不會重新簽發；vendor 或
// paragonie/paseto 任何破壞相容性的變更，都會讓所有已出貨的 App 同時失效。
//
// ⚠️ 升級套件後**不要**重新產生這份 fixture——那等於拿新版程式碼驗新版 token，
//    守護意義全失。只有在刻意放棄舊 token 相容性時才重新產生。
//
// 執行方式：
//   ./vendor/bin/sail php tests/Fixtures/generate_legacy_token_fixture.php \
//       > tests/Fixtures/legacy_token_fixture.json
//
// 全程使用 in-memory SQLite 與測試專用 passphrase，不碰任何真實 DB、金鑰或 log。
// fixture 內的金鑰只存在於這份檔案，從未用於任何環境。
// ============================================================================

use App\Models\ContentEncryptionKey;
use App\Models\License;
use App\Models\LicenseScope;
use Composer\InstalledVersions;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use LucaLongo\Licensing\Enums\LicenseStatus;
use LucaLongo\Licensing\Models\LicensingKey;

const KEYSTORE_PASSPHRASE = 'legacy-token-fixture-passphrase';
const LICENSE_KEY = 'LEGACY-TOKEN-FIXTURE-0001';
const FINGERPRINT = 'legacy-token-fixture-device';

// 必須在 bootstrap 前寫入：Laravel 的 Dotenv 為 immutable，已存在的變數不會被 .env 覆蓋。
$testingEnv = [
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
    'DB_URL' => '',
    'CACHE_STORE' => 'array',
    'SESSION_DRIVER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    'MAIL_MAILER' => 'array',
    'LOG_CHANNEL' => 'null',
    'NIGHTWATCH_ENABLED' => 'false',
];
foreach ($testingEnv as $name => $value) {
    putenv("{$name}={$value}");
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

config([
    'licensing.crypto.keystore.driver' => 'database',
    'licensing.crypto.keystore.passphrase' => KEYSTORE_PASSPHRASE,
    'logging.channels.api' => config('logging.channels.null'),
]);
LicensingKey::forgetCachedPassphrase();

/**
 * Artisan 呼叫失敗時中止：靜默略過會產出一份「看起來正常」卻無法重播的 fixture。
 */
function mustSucceed(int $exitCode, string $command): void
{
    if ($exitCode !== 0) {
        fwrite(STDERR, "{$command} failed:\n".Artisan::output());
        exit(1);
    }
}

/**
 * 經由 HTTP kernel 呼叫 API，與正式站同樣穿過 middleware 與 controller。
 *
 * @param  array<string, mixed>  $payload
 * @return array<string, mixed>
 */
function postApi(HttpKernel $kernel, string $uri, array $payload, ?string $bearer = null): array
{
    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'REMOTE_ADDR' => '127.0.0.1',
    ];
    if ($bearer !== null) {
        $server['HTTP_AUTHORIZATION'] = 'Bearer '.$bearer;
    }

    $request = Request::create($uri, 'POST', [], [], [], $server, json_encode($payload, JSON_THROW_ON_ERROR));
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);

    if ($response->getStatusCode() !== 200) {
        fwrite(STDERR, "{$uri} returned {$response->getStatusCode()}:\n{$response->getContent()}\n");
        exit(1);
    }

    return json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)['data'];
}

mustSucceed(Artisan::call('migrate', ['--force' => true]), 'migrate');
mustSucceed(Artisan::call('licensing:keys:make-root'), 'licensing:keys:make-root');
// 百年效期：fixture 不該因 signing key 的 valid_until 而變成定時炸彈。
mustSucceed(Artisan::call('licensing:keys:issue-signing', ['--days' => 36500]), 'licensing:keys:issue-signing');

$cek = ContentEncryptionKey::create([
    'name' => 'legacy-token-fixture-cek',
    'encrypted_key' => base64_encode(random_bytes(32)),
]);
$scope = LicenseScope::create([
    'name' => 'Legacy Token Fixture',
    'is_active' => true,
    'content_encryption_key_id' => $cek->id,
]);
// 永久授權：正式站 token TTL 最長的一類，也是升級後最不可能被重新簽發的一類。
$license = License::create([
    'key_hash' => License::hashKey(LICENSE_KEY),
    'status' => LicenseStatus::Active,
    'license_scope_id' => $scope->id,
    'activated_at' => now(),
    'expires_at' => null,
    'max_usages' => 1,
    'meta' => [],
]);

$httpKernel = $app->make(HttpKernel::class);

$activation = postApi($httpKernel, '/api/licensing/v1/activate', [
    'license_key' => LICENSE_KEY,
    'fingerprint' => FINGERPRINT,
    'client_ephemeral_public_key' => base64_encode(sodium_crypto_box_publickey(sodium_crypto_box_keypair())),
]);
$validation = postApi($httpKernel, '/api/licensing/v1/validate', [
    'license_key' => LICENSE_KEY,
    'fingerprint' => FINGERPRINT,
], $activation['token']);

echo json_encode([
    '_readme' => '由 tests/Fixtures/generate_legacy_token_fixture.php 產生。升級套件後不得重新產生，理由見產生器檔頭。',
    'generated_at' => now()->toIso8601String(),
    'generated_with' => array_combine(
        $packages = ['masterix21/laravel-licensing', 'paragonie/paseto', 'laravel/framework'],
        array_map(fn (string $package): ?string => InstalledVersions::getPrettyVersion($package), $packages),
    ),
    'issuer' => config('licensing.offline_token.issuer'),
    'keystore_passphrase' => KEYSTORE_PASSPHRASE,
    'licensing_keys' => DB::table('licensing_keys')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
    'license' => [
        'id' => $license->id,
        'license_key' => LICENSE_KEY,
        'fingerprint' => FINGERPRINT,
    ],
    'activate_token' => $activation['token'],
    'validation_token' => $validation['validation_token'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
