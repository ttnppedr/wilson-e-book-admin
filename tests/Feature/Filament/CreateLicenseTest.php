<?php

use App\Filament\Resources\LicenseResource\Pages\CreateLicense;
use App\Models\License;
use App\Models\LicenseScope;
use App\Models\User;
use App\Services\LicenseKeyGenerator;
use Filament\Notifications\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->actingAs(User::factory()->create());

    $this->scope = LicenseScope::create([
        'name' => 'Test Scope',
        'is_active' => true,
        'default_max_usages' => 3,
        'default_duration_days' => 365,
    ]);
});

test('admin sees the new license key right after creating a license', function (): void {
    $component = Livewire::test(CreateLicense::class)
        ->fillForm([
            'license_scope_id' => $this->scope->getKey(),
            'name' => '新授權',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $key = License::query()->where('name', '新授權')->firstOrFail()->retrieveKey();

    $component->assertNotified(
        Notification::make()
            ->title(__('laravel-licensing-filament-manager::license.notifications.key_generated'))
            ->body(__('laravel-licensing-filament-manager::license.notifications.key_value', ['key' => LicenseKeyGenerator::format($key)]))
            ->success()
            ->persistent(),
    );
});
