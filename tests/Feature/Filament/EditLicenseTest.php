<?php

use App\Filament\Resources\LicenseResource\Pages\EditLicense;
use App\Models\License;
use App\Models\LicenseScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use LucaLongo\Licensing\Enums\LicenseStatus;

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

$createLicense = function (LicenseScope $scope, array $attributes = []): License {
    return License::create(array_merge([
        'key_hash' => hash('sha256', fake()->uuid()),
        'status' => LicenseStatus::Pending,
        'license_scope_id' => $scope->getKey(),
        'name' => 'Test License',
        'expires_at' => now()->addDays(365),
        'max_usages' => 3,
        'meta' => [],
    ], $attributes));
};

test('can save name change', function () use ($createLicense): void {
    $license = $createLicense($this->scope, [
        'status' => LicenseStatus::Active,
        'activated_at' => now(),
    ]);

    Livewire::test(EditLicense::class, ['record' => $license->getRouteKey()])
        ->fillForm(['name' => 'Updated Name'])
        ->call('save')
        ->assertHasNoFormErrors();

    $license->refresh();
    $this->assertSame('Updated Name', $license->name);
});

test('can change status to suspended via dropdown', function () use ($createLicense): void {
    $license = $createLicense($this->scope, [
        'status' => LicenseStatus::Active,
        'activated_at' => now(),
    ]);

    Livewire::test(EditLicense::class, ['record' => $license->getRouteKey()])
        ->fillForm(['status' => LicenseStatus::Suspended->value])
        ->call('save')
        ->assertHasNoFormErrors();

    $license->refresh();
    $this->assertSame(LicenseStatus::Suspended, $license->status);
});

test('can change status to cancelled via dropdown', function () use ($createLicense): void {
    $license = $createLicense($this->scope, [
        'status' => LicenseStatus::Active,
        'activated_at' => now(),
    ]);

    Livewire::test(EditLicense::class, ['record' => $license->getRouteKey()])
        ->fillForm(['status' => LicenseStatus::Cancelled->value])
        ->call('save')
        ->assertHasNoFormErrors();

    $license->refresh();
    $this->assertSame(LicenseStatus::Cancelled, $license->status);
});

test('delete action visible for pending license', function () use ($createLicense): void {
    $license = $createLicense($this->scope, ['status' => LicenseStatus::Pending]);

    Livewire::test(EditLicense::class, ['record' => $license->getRouteKey()])
        ->assertActionVisible('delete');
});

test('delete action hidden for active license', function () use ($createLicense): void {
    $license = $createLicense($this->scope, [
        'status' => LicenseStatus::Active,
        'activated_at' => now(),
    ]);

    Livewire::test(EditLicense::class, ['record' => $license->getRouteKey()])
        ->assertActionHidden('delete');
});

test('sensitive attributes are hidden from serialization', function () use ($createLicense): void {
    $license = $createLicense($this->scope, [
        'meta' => ['encrypted_key' => 'SENSITIVE-CIPHERTEXT-MARKER'],
    ]);

    $serialized = $license->fresh()->toArray();

    $this->assertArrayNotHasKey('key_hash', $serialized);
    $this->assertArrayNotHasKey('meta', $serialized);
});

test('edit page does not expose key hash or encrypted key', function () use ($createLicense): void {
    $license = $createLicense($this->scope, [
        'status' => LicenseStatus::Active,
        'activated_at' => now(),
        'meta' => ['encrypted_key' => 'SENSITIVE-CIPHERTEXT-MARKER'],
    ]);

    // Filament Edit 頁以 attributesToArray() 填表單，非 $hidden 屬性會經
    // Livewire snapshot 序列化到前端 JS——斷言兩個敏感欄位不再出現在渲染輸出
    Livewire::test(EditLicense::class, ['record' => $license->getRouteKey()])
        ->assertDontSee($license->key_hash)
        ->assertDontSee('SENSITIVE-CIPHERTEXT-MARKER');
});
