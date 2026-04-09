<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\LicenseResource\Pages\EditLicense;
use App\Models\License;
use App\Models\LicenseScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use LucaLongo\Licensing\Enums\LicenseStatus;
use Tests\TestCase;

class EditLicenseTest extends TestCase
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

    private function createLicense(array $attributes = []): License
    {
        return License::create(array_merge([
            'key_hash' => hash('sha256', fake()->uuid()),
            'status' => LicenseStatus::Pending,
            'license_scope_id' => $this->scope->getKey(),
            'name' => 'Test License',
            'expires_at' => now()->addDays(365),
            'max_usages' => 3,
            'meta' => [],
        ], $attributes));
    }

    public function test_can_save_name_change(): void
    {
        $license = $this->createLicense([
            'status' => LicenseStatus::Active,
            'activated_at' => now(),
        ]);

        Livewire::test(EditLicense::class, ['record' => $license->getRouteKey()])
            ->fillForm(['name' => 'Updated Name'])
            ->call('save')
            ->assertHasNoFormErrors();

        $license->refresh();
        $this->assertSame('Updated Name', $license->name);
    }

    public function test_can_change_status_to_suspended_via_dropdown(): void
    {
        $license = $this->createLicense([
            'status' => LicenseStatus::Active,
            'activated_at' => now(),
        ]);

        Livewire::test(EditLicense::class, ['record' => $license->getRouteKey()])
            ->fillForm(['status' => LicenseStatus::Suspended->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $license->refresh();
        $this->assertSame(LicenseStatus::Suspended, $license->status);
    }

    public function test_can_change_status_to_cancelled_via_dropdown(): void
    {
        $license = $this->createLicense([
            'status' => LicenseStatus::Active,
            'activated_at' => now(),
        ]);

        Livewire::test(EditLicense::class, ['record' => $license->getRouteKey()])
            ->fillForm(['status' => LicenseStatus::Cancelled->value])
            ->call('save')
            ->assertHasNoFormErrors();

        $license->refresh();
        $this->assertSame(LicenseStatus::Cancelled, $license->status);
    }

    public function test_delete_action_visible_for_pending_license(): void
    {
        $license = $this->createLicense(['status' => LicenseStatus::Pending]);

        Livewire::test(EditLicense::class, ['record' => $license->getRouteKey()])
            ->assertActionVisible('delete');
    }

    public function test_delete_action_hidden_for_active_license(): void
    {
        $license = $this->createLicense([
            'status' => LicenseStatus::Active,
            'activated_at' => now(),
        ]);

        Livewire::test(EditLicense::class, ['record' => $license->getRouteKey()])
            ->assertActionHidden('delete');
    }
}
