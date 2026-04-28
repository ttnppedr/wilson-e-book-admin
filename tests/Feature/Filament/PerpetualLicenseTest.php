<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\LicenseResource\Pages\CreateLicense;
use App\Filament\Resources\LicenseResource\Pages\EditLicense;
use App\Models\License;
use App\Models\LicenseScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use LucaLongo\Licensing\Enums\LicenseStatus;
use OwenIt\Auditing\Models\Audit;
use Tests\TestCase;

class PerpetualLicenseTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private LicenseScope $scope;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->actingAs($this->admin);

        $this->scope = LicenseScope::create([
            'name' => 'Test Scope',
            'is_active' => true,
            'default_max_usages' => 3,
            'default_duration_days' => 365,
        ]);
    }

    public function test_can_create_perpetual_license_via_form(): void
    {
        Livewire::test(CreateLicense::class)
            ->fillForm([
                'license_scope_id' => $this->scope->getKey(),
                'name' => '永久授權',
                'is_perpetual' => true,
                'expires_at' => null,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $license = License::query()->where('name', '永久授權')->firstOrFail();
        $this->assertNull($license->expires_at);
        $this->assertFalse($license->isExpired());
    }

    public function test_perpetual_toggle_clears_expires_at_when_checked(): void
    {
        Livewire::test(CreateLicense::class)
            ->fillForm([
                'license_scope_id' => $this->scope->getKey(),
                'name' => '勾選永久',
            ])
            ->assertFormSet(fn (array $state) => ! empty($state['expires_at']))
            ->fillForm(['is_perpetual' => true])
            ->assertFormSet(['expires_at' => null]);
    }

    public function test_perpetual_toggle_disables_expires_at_field(): void
    {
        Livewire::test(CreateLicense::class)
            ->fillForm([
                'license_scope_id' => $this->scope->getKey(),
                'is_perpetual' => true,
            ])
            ->assertFormFieldIsDisabled('expires_at');
    }

    public function test_unchecking_perpetual_toggle_re_enables_expires_at(): void
    {
        Livewire::test(CreateLicense::class)
            ->fillForm([
                'license_scope_id' => $this->scope->getKey(),
                'is_perpetual' => true,
            ])
            ->assertFormFieldIsDisabled('expires_at')
            ->fillForm(['is_perpetual' => false])
            ->assertFormFieldIsEnabled('expires_at');
    }

    public function test_perpetual_toggle_pre_checked_when_editing_perpetual_license(): void
    {
        $license = License::create([
            'key_hash' => hash('sha256', fake()->uuid()),
            'status' => LicenseStatus::Pending,
            'license_scope_id' => $this->scope->getKey(),
            'name' => '永久授權',
            'expires_at' => null,
            'max_usages' => 3,
            'meta' => [],
        ]);

        Livewire::test(EditLicense::class, ['record' => $license->getRouteKey()])
            ->assertFormSet([
                'is_perpetual' => true,
                'expires_at' => null,
            ])
            ->assertFormFieldIsDisabled('expires_at');
    }

    public function test_perpetual_toggle_unchecked_when_editing_non_perpetual_license(): void
    {
        $license = License::create([
            'key_hash' => hash('sha256', fake()->uuid()),
            'status' => LicenseStatus::Pending,
            'license_scope_id' => $this->scope->getKey(),
            'name' => '一年期授權',
            'expires_at' => now()->addDays(365),
            'max_usages' => 3,
            'meta' => [],
        ]);

        Livewire::test(EditLicense::class, ['record' => $license->getRouteKey()])
            ->assertFormSet(['is_perpetual' => false])
            ->assertFormFieldIsEnabled('expires_at');
    }

    public function test_can_save_perpetual_via_edit_when_disabled_field_dehydrates_null(): void
    {
        $license = License::create([
            'key_hash' => hash('sha256', fake()->uuid()),
            'status' => LicenseStatus::Pending,
            'license_scope_id' => $this->scope->getKey(),
            'name' => '原本一年',
            'expires_at' => now()->addDays(365),
            'max_usages' => 3,
            'meta' => [],
        ]);

        Livewire::test(EditLicense::class, ['record' => $license->getRouteKey()])
            ->fillForm([
                'is_perpetual' => true,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $license->refresh();
        $this->assertNull($license->expires_at);
    }

    public function test_changing_to_perpetual_writes_audit_with_expires_at_diff(): void
    {
        $originalExpiry = now()->addDays(365)->startOfSecond();

        $license = License::create([
            'key_hash' => hash('sha256', fake()->uuid()),
            'status' => LicenseStatus::Pending,
            'license_scope_id' => $this->scope->getKey(),
            'name' => '一年期改永久',
            'expires_at' => $originalExpiry,
            'max_usages' => 3,
            'meta' => [],
        ]);

        Livewire::test(EditLicense::class, ['record' => $license->getRouteKey()])
            ->fillForm(['is_perpetual' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $audit = Audit::query()
            ->where('auditable_type', $license->getMorphClass())
            ->where('auditable_id', $license->getKey())
            ->where('event', 'updated')
            ->latest('id')
            ->firstOrFail();

        $this->assertSame((string) $this->admin->getKey(), (string) $audit->user_id);
        $this->assertNotNull($audit->old_values['expires_at']);
        $this->assertNull($audit->new_values['expires_at']);
    }

    public function test_creating_perpetual_license_writes_audit_with_null_expires_at(): void
    {
        Livewire::test(CreateLicense::class)
            ->fillForm([
                'license_scope_id' => $this->scope->getKey(),
                'name' => '建立永久授權',
                'is_perpetual' => true,
                'expires_at' => null,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $license = License::query()->where('name', '建立永久授權')->firstOrFail();

        $audit = Audit::query()
            ->where('auditable_type', $license->getMorphClass())
            ->where('auditable_id', $license->getKey())
            ->where('event', 'created')
            ->firstOrFail();

        $this->assertSame((string) $this->admin->getKey(), (string) $audit->user_id);
        $this->assertArrayHasKey('expires_at', $audit->new_values);
        $this->assertNull($audit->new_values['expires_at']);
    }
}
