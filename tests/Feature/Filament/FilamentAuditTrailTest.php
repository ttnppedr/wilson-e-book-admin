<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\LicenseResource\Pages\EditLicense;
use App\Filament\Resources\LicenseScopeResource\Pages\CreateLicenseScope;
use App\Filament\Resources\LicenseScopeResource\Pages\EditLicenseScope;
use App\Models\ContentEncryptionKey;
use App\Models\License;
use App\Models\LicenseScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use LucaLongo\Licensing\Enums\LicenseStatus;
use OwenIt\Auditing\Models\Audit;
use Tests\TestCase;

class FilamentAuditTrailTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private ContentEncryptionKey $cek;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->actingAs($this->admin);

        $this->cek = ContentEncryptionKey::create([
            'name' => 'Test CEK',
            'encrypted_key' => ContentEncryptionKey::generateKey(),
        ]);
    }

    public function test_filament_create_license_scope_writes_audit_with_actor(): void
    {
        Livewire::test(CreateLicenseScope::class)
            ->fillForm([
                'name' => 'Filament 建立的 Scope',
                'is_active' => true,
                'default_max_usages' => 5,
                'default_duration_days' => 180,
                'content_encryption_key_id' => $this->cek->getKey(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $scope = LicenseScope::query()->where('name', 'Filament 建立的 Scope')->firstOrFail();

        $audit = Audit::query()
            ->where('auditable_type', $scope->getMorphClass())
            ->where('auditable_id', $scope->getKey())
            ->where('event', 'created')
            ->firstOrFail();

        $this->assertSame((string) $this->admin->getKey(), (string) $audit->user_id);
        $this->assertSame($this->admin::class, $audit->user_type);
        $this->assertSame('Filament 建立的 Scope', $audit->new_values['name']);
    }

    public function test_filament_edit_license_scope_writes_audit_with_diff(): void
    {
        $scope = LicenseScope::create([
            'name' => '原名稱',
            'is_active' => true,
            'default_max_usages' => 1,
            'default_duration_days' => 30,
            'content_encryption_key_id' => $this->cek->getKey(),
        ]);

        Livewire::test(EditLicenseScope::class, ['record' => $scope->getRouteKey()])
            ->fillForm(['name' => 'Filament 改名稱'])
            ->call('save')
            ->assertHasNoFormErrors();

        $audit = Audit::query()
            ->where('auditable_type', $scope->getMorphClass())
            ->where('auditable_id', $scope->getKey())
            ->where('event', 'updated')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame((string) $this->admin->getKey(), (string) $audit->user_id);
        $this->assertSame('原名稱', $audit->old_values['name']);
        $this->assertSame('Filament 改名稱', $audit->new_values['name']);
    }

    public function test_filament_edit_license_writes_audit_with_actor(): void
    {
        $scope = LicenseScope::create([
            'name' => 'Scope',
            'is_active' => true,
            'default_max_usages' => 3,
            'default_duration_days' => 365,
        ]);

        $license = License::create([
            'key_hash' => hash('sha256', fake()->uuid()),
            'status' => LicenseStatus::Active,
            'license_scope_id' => $scope->getKey(),
            'name' => '舊 License 名稱',
            'activated_at' => now(),
            'expires_at' => now()->addDays(365),
            'max_usages' => 3,
            'meta' => [],
        ]);

        Livewire::test(EditLicense::class, ['record' => $license->getRouteKey()])
            ->fillForm(['name' => 'Filament 改 License 名稱'])
            ->call('save')
            ->assertHasNoFormErrors();

        $audit = Audit::query()
            ->where('auditable_type', $license->getMorphClass())
            ->where('auditable_id', $license->getKey())
            ->where('event', 'updated')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame((string) $this->admin->getKey(), (string) $audit->user_id);
        $this->assertSame('舊 License 名稱', $audit->old_values['name']);
        $this->assertSame('Filament 改 License 名稱', $audit->new_values['name']);
    }

    public function test_filament_delete_action_writes_audit(): void
    {
        $scope = LicenseScope::create([
            'name' => 'Scope',
            'is_active' => true,
            'default_max_usages' => 3,
            'default_duration_days' => 365,
        ]);

        $license = License::create([
            'key_hash' => hash('sha256', fake()->uuid()),
            'status' => LicenseStatus::Pending,
            'license_scope_id' => $scope->getKey(),
            'name' => '待刪除 License',
            'expires_at' => now()->addDays(365),
            'max_usages' => 3,
            'meta' => [],
        ]);
        $licenseId = $license->getKey();
        $licenseType = $license->getMorphClass();

        Livewire::test(EditLicense::class, ['record' => $license->getRouteKey()])
            ->callAction('delete');

        $this->assertDatabaseHas('audits', [
            'auditable_type' => $licenseType,
            'auditable_id' => $licenseId,
            'event' => 'deleted',
            'user_id' => $this->admin->getKey(),
        ]);
    }
}
