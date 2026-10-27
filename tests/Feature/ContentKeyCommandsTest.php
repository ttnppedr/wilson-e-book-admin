<?php

/**
 * content-key:create / content-key:list 指令。
 *
 * content-key:create 產出的金鑰不是標準 base64（剔除 +/= 後截 43 字），activate 卻以
 * base64_decode(strict) 解碼並要求剛好 32 bytes——兩者的相容性只靠 PHP 對缺 padding
 * 的寬容。一旦失守，新產品線所有授權的 activate 都會回 500 MISSING_CONTENT_KEY，
 * 因此以「指令建出的金鑰能讓 activate 成功」端對端守護。
 */

use App\Models\ContentEncryptionKey;
use App\Models\License;
use App\Models\LicenseScope;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LucaLongo\Licensing\Enums\LicenseStatus;
use Tests\Concerns\CreatesLicensingKeys;

uses(CreatesLicensingKeys::class, RefreshDatabase::class);

test('a content key created by the command is usable by activate', function (): void {
    $this->createLicensingKeys();

    $this->artisan('content-key:create', ['name' => 'english-v1'])->assertExitCode(0);

    $scope = LicenseScope::create([
        'name' => 'English',
        'is_active' => true,
        'content_encryption_key_id' => ContentEncryptionKey::where('name', 'english-v1')->value('id'),
    ]);
    License::create([
        'key_hash' => License::hashKey('CLIKEYTEST0000000001'),
        'status' => LicenseStatus::Active,
        'license_scope_id' => $scope->id,
        'activated_at' => now(),
        'expires_at' => now()->addDays(30),
        'max_usages' => 1,
        'meta' => [],
    ]);

    $this->postJson('/api/licensing/v1/activate', [
        'license_key' => 'CLIKEYTEST0000000001',
        'fingerprint' => 'cli-key-device',
        'client_ephemeral_public_key' => base64_encode(sodium_crypto_box_publickey(sodium_crypto_box_keypair())),
    ])->assertStatus(200);
});

test('create refuses a duplicate name', function (): void {
    $this->artisan('content-key:create', ['name' => 'english-v1'])->assertExitCode(0);

    $this->artisan('content-key:create', ['name' => 'english-v1'])->assertExitCode(1);

    $this->assertSame(1, ContentEncryptionKey::where('name', 'english-v1')->count());
});

test('list shows every key', function (): void {
    $this->artisan('content-key:list')
        ->expectsOutputToContain('尚未建立任何加密金鑰')
        ->assertExitCode(0);

    $this->artisan('content-key:create', ['name' => 'english-v1'])->assertExitCode(0);

    $this->artisan('content-key:list')
        ->expectsOutputToContain('english-v1')
        ->assertExitCode(0);
});
