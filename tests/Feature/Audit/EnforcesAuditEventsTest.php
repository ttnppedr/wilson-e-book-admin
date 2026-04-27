<?php

namespace Tests\Feature\Audit;

use App\Models\ContentEncryptionKey;
use App\Models\License;
use App\Models\LicenseScope;
use App\Models\LicenseUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use LucaLongo\Licensing\Enums\LicenseStatus;
use Tests\TestCase;

/**
 * 驗證 EnforcesAuditEvents trait 在 audit target model 上會擋下 saveQuietly/updateQuietly/deleteQuietly。
 */
class EnforcesAuditEventsTest extends TestCase
{
    use RefreshDatabase;

    private LicenseScope $scope;

    private License $license;

    private LicenseUsage $usage;

    protected function setUp(): void
    {
        parent::setUp();

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
    }

    public function test_license_save_quietly_throws(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('App\Models\License');
        $this->expectExceptionMessage('saveQuietly');

        $this->license->name = '改名';
        $this->license->saveQuietly();
    }

    public function test_license_update_quietly_throws(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('updateQuietly');

        $this->license->updateQuietly(['name' => '改名']);
    }

    public function test_license_delete_quietly_throws(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('deleteQuietly');

        $this->license->deleteQuietly();
    }

    public function test_license_scope_save_quietly_throws(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('App\Models\LicenseScope');

        $this->scope->saveQuietly();
    }

    public function test_license_usage_save_quietly_throws(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('App\Models\LicenseUsage');

        $this->usage->saveQuietly();
    }

    public function test_normal_save_still_works(): void
    {
        $this->license->name = '走正常 save 流程';
        $this->license->save();

        $this->assertSame('走正常 save 流程', $this->license->fresh()->name);
    }
}
