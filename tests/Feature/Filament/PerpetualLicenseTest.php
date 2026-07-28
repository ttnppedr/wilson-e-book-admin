<?php

use App\Filament\Resources\LicenseResource\Pages\CreateLicense;
use App\Filament\Resources\LicenseResource\Pages\EditLicense;
use App\Filament\Resources\LicenseResource\Pages\ListLicenses;
use App\Models\License;
use App\Models\LicenseScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use LucaLongo\Licensing\Enums\LicenseStatus;
use OwenIt\Auditing\Models\Audit;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = User::factory()->create();
    $this->actingAs($this->admin);

    $this->scope = LicenseScope::create([
        'name' => 'Test Scope',
        'is_active' => true,
        'default_max_usages' => 3,
        'default_duration_days' => 365,
    ]);
});

test('can create perpetual license via form', function (): void {
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
});

test('creating with perpetual scope auto checks toggle', function (): void {
    $perpetualScope = LicenseScope::create([
        'name' => '永久範圍',
        'is_active' => true,
        'default_max_usages' => 1,
        'default_duration_days' => null,
    ]);

    Livewire::test(CreateLicense::class)
        ->fillForm(['license_scope_id' => $perpetualScope->getKey()])
        ->assertFormSet([
            'is_perpetual' => true,
            'expires_at' => null,
        ])
        ->assertFormFieldIsDisabled('expires_at');
});

test('creating with dated scope auto fills expires at', function (): void {
    Livewire::test(CreateLicense::class)
        ->fillForm(['license_scope_id' => $this->scope->getKey()])
        ->assertFormSet([
            'is_perpetual' => false,
        ])
        ->assertFormSet(fn (array $state) => ! empty($state['expires_at']))
        ->assertFormFieldIsEnabled('expires_at');
});

test('editing does not overwrite perpetual state when scope changes', function (): void {
    $perpetualScope = LicenseScope::create([
        'name' => '永久範圍',
        'is_active' => true,
        'default_max_usages' => 1,
        'default_duration_days' => null,
    ]);

    $originalExpiry = now()->addDays(365)->startOfSecond();

    $license = License::create([
        'key_hash' => hash('sha256', fake()->uuid()),
        'status' => LicenseStatus::Pending,
        'license_scope_id' => $this->scope->getKey(),
        'name' => '一年期',
        'expires_at' => $originalExpiry,
        'max_usages' => 3,
        'meta' => [],
    ]);

    Livewire::test(EditLicense::class, ['record' => $license->getRouteKey()])
        ->fillForm(['license_scope_id' => $perpetualScope->getKey()])
        ->assertFormSet([
            'is_perpetual' => false,
        ])
        ->assertFormSet(fn (array $state) => $state['expires_at'] !== null);
});

test('perpetual toggle clears expires at when checked', function (): void {
    Livewire::test(CreateLicense::class)
        ->fillForm([
            'license_scope_id' => $this->scope->getKey(),
            'name' => '勾選永久',
        ])
        ->assertFormSet(fn (array $state) => ! empty($state['expires_at']))
        ->fillForm(['is_perpetual' => true])
        ->assertFormSet(['expires_at' => null]);
});

test('perpetual toggle disables expires at field', function (): void {
    Livewire::test(CreateLicense::class)
        ->fillForm([
            'license_scope_id' => $this->scope->getKey(),
            'is_perpetual' => true,
        ])
        ->assertFormFieldIsDisabled('expires_at');
});

test('unchecking perpetual toggle re enables expires at', function (): void {
    Livewire::test(CreateLicense::class)
        ->fillForm([
            'license_scope_id' => $this->scope->getKey(),
            'is_perpetual' => true,
        ])
        ->assertFormFieldIsDisabled('expires_at')
        ->fillForm(['is_perpetual' => false])
        ->assertFormFieldIsEnabled('expires_at');
});

test('perpetual toggle pre checked when editing perpetual license', function (): void {
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
});

test('perpetual toggle unchecked when editing non perpetual license', function (): void {
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
});

test('can save perpetual via edit when disabled field dehydrates null', function (): void {
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
});

test('changing to perpetual writes audit with expires at diff', function (): void {
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
});

test('table is perpetual column checked when expires at is null', function (): void {
    $perpetual = License::create([
        'key_hash' => hash('sha256', fake()->uuid()),
        'status' => LicenseStatus::Pending,
        'license_scope_id' => $this->scope->getKey(),
        'name' => '永久授權',
        'expires_at' => null,
        'max_usages' => 3,
        'meta' => [],
    ]);

    $dated = License::create([
        'key_hash' => hash('sha256', fake()->uuid()),
        'status' => LicenseStatus::Pending,
        'license_scope_id' => $this->scope->getKey(),
        'name' => '一年期授權',
        'expires_at' => now()->addDays(365),
        'max_usages' => 3,
        'meta' => [],
    ]);

    Livewire::test(ListLicenses::class)
        ->assertTableColumnExists('is_perpetual')
        ->assertCanSeeTableRecords([$perpetual, $dated])
        ->assertTableColumnStateSet('is_perpetual', true, $perpetual)
        ->assertTableColumnStateSet('is_perpetual', false, $dated);
});

test('creating perpetual license writes audit with null expires at', function (): void {
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
});
