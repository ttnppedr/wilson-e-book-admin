<?php

/**
 * 驗證 EnforcesAuditEvents trait 在 audit target model 上會擋下 saveQuietly/updateQuietly/deleteQuietly。
 */

use App\Models\ContentEncryptionKey;
use App\Models\License;
use App\Models\LicenseScope;
use App\Models\LicenseUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LucaLongo\Licensing\Enums\LicenseStatus;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $cek = ContentEncryptionKey::create([
        'name' => 'EnforceTest CEK',
        'encrypted_key' => ContentEncryptionKey::generateKey(),
    ]);

    $this->scope = LicenseScope::create([
        'name' => 'EnforceTest Scope',
        'is_active' => true,
        'default_max_usages' => 3,
        'default_duration_days' => 30,
        'content_encryption_key_id' => $cek->getKey(),
    ]);

    $this->license = License::create([
        'key_hash' => hash('sha256', fake()->uuid()),
        'status' => LicenseStatus::Active,
        'license_scope_id' => $this->scope->getKey(),
        'name' => 'EnforceTest License',
        'activated_at' => now(),
        'expires_at' => now()->addDays(30),
        'max_usages' => 3,
        'meta' => [],
    ]);

    $this->usage = LicenseUsage::create([
        'license_id' => $this->license->getKey(),
        'usage_fingerprint' => fake()->uuid(),
        'status' => 'active',
        'registered_at' => now(),
        'last_seen_at' => now(),
        'client_type' => 'test',
        'meta' => [],
    ]);
});

test('license save quietly throws', function (): void {
    $this->expectException(LogicException::class);
    $this->expectExceptionMessage('App\Models\License');
    $this->expectExceptionMessage('saveQuietly');

    $this->license->name = '改名';
    $this->license->saveQuietly();
});

test('license update quietly throws', function (): void {
    $this->expectException(LogicException::class);
    $this->expectExceptionMessage('updateQuietly');

    $this->license->updateQuietly(['name' => '改名']);
});

test('license delete quietly throws', function (): void {
    $this->expectException(LogicException::class);
    $this->expectExceptionMessage('deleteQuietly');

    $this->license->deleteQuietly();
});

test('license scope save quietly throws', function (): void {
    $this->expectException(LogicException::class);
    $this->expectExceptionMessage('App\Models\LicenseScope');

    $this->scope->saveQuietly();
});

test('license usage save quietly throws', function (): void {
    $this->expectException(LogicException::class);
    $this->expectExceptionMessage('App\Models\LicenseUsage');

    $this->usage->saveQuietly();
});

test('normal save still works', function (): void {
    $this->license->name = '走正常 save 流程';
    $this->license->save();

    $this->assertSame('走正常 save 流程', $this->license->fresh()->name);
});
