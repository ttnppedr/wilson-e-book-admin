<?php

use App\Models\License;
use App\Models\LicenseScope;
use App\Models\LicenseUsage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LucaLongo\Licensing\Enums\LicenseStatus;
use LucaLongo\Licensing\Enums\UsageStatus;
use OwenIt\Auditing\Models\Audit;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = User::factory()->create();
    $this->actingAs($this->admin);
});

$makeScope = function (): LicenseScope {
    return LicenseScope::create([
        'name' => 'Test Scope',
        'is_active' => true,
        'default_max_usages' => 3,
        'default_duration_days' => 365,
    ]);
};

$makeLicense = function (LicenseScope $scope): License {
    return License::create([
        'key_hash' => hash('sha256', fake()->uuid()),
        'status' => LicenseStatus::Pending,
        'license_scope_id' => $scope->getKey(),
        'name' => 'Test License',
        'expires_at' => now()->addDays(365),
        'max_usages' => 3,
        'meta' => [],
    ]);
};

$makeUsage = function (License $license): LicenseUsage {
    return LicenseUsage::create([
        'license_id' => $license->getKey(),
        'usage_fingerprint' => fake()->uuid(),
        'status' => UsageStatus::Active,
        'registered_at' => now(),
        'last_seen_at' => now(),
        'client_type' => 'desktop',
        'meta' => [],
    ]);
};

test('creating license scope writes audit record with actor', function (): void {
    $scope = LicenseScope::create([
        'name' => 'Scope A',
        'is_active' => true,
        'default_max_usages' => 3,
        'default_duration_days' => 365,
    ]);

    $audit = Audit::query()
        ->where('auditable_type', $scope->getMorphClass())
        ->where('auditable_id', $scope->getKey())
        ->where('event', 'created')
        ->firstOrFail();

    $this->assertSame((string) $this->admin->getKey(), (string) $audit->user_id);
    $this->assertSame($this->admin::class, $audit->user_type);
    $this->assertSame('Scope A', $audit->new_values['name']);
});

test('updating license scope writes audit with diff', function (): void {
    $scope = LicenseScope::create([
        'name' => 'Original',
        'is_active' => true,
        'default_max_usages' => 1,
        'default_duration_days' => 30,
    ]);

    $scope->update(['name' => 'Renamed']);

    $audit = Audit::query()
        ->where('auditable_type', $scope->getMorphClass())
        ->where('auditable_id', $scope->getKey())
        ->where('event', 'updated')
        ->firstOrFail();

    $this->assertSame('Original', $audit->old_values['name']);
    $this->assertSame('Renamed', $audit->new_values['name']);
});

test('deleting license scope writes audit record', function (): void {
    $scope = LicenseScope::create([
        'name' => 'Throwaway',
        'is_active' => true,
        'default_max_usages' => 1,
        'default_duration_days' => 30,
    ]);
    $scopeId = $scope->getKey();

    $scope->delete();

    $this->assertDatabaseHas('audits', [
        'auditable_type' => (new LicenseScope)->getMorphClass(),
        'auditable_id' => $scopeId,
        'event' => 'deleted',
        'user_id' => $this->admin->getKey(),
    ]);
});

test('creating license writes audit record', function () use ($makeScope, $makeLicense): void {
    $scope = $makeScope();
    $license = $makeLicense($scope);

    $this->assertDatabaseHas('audits', [
        'auditable_type' => $license->getMorphClass(),
        'auditable_id' => $license->getKey(),
        'event' => 'created',
        'user_id' => $this->admin->getKey(),
    ]);
});

test('updating license writes audit with diff', function () use ($makeScope, $makeLicense): void {
    $license = $makeLicense($makeScope());

    $license->update(['max_usages' => 99]);

    $audit = Audit::query()
        ->where('auditable_type', $license->getMorphClass())
        ->where('auditable_id', $license->getKey())
        ->where('event', 'updated')
        ->latest('id')
        ->firstOrFail();

    $this->assertSame(3, $audit->old_values['max_usages']);
    $this->assertSame(99, $audit->new_values['max_usages']);
});

test('deleting license writes audit record', function () use ($makeScope, $makeLicense): void {
    $license = $makeLicense($makeScope());
    $licenseId = $license->getKey();
    $licenseType = $license->getMorphClass();

    $license->delete();

    $this->assertDatabaseHas('audits', [
        'auditable_type' => $licenseType,
        'auditable_id' => $licenseId,
        'event' => 'deleted',
        'user_id' => $this->admin->getKey(),
    ]);
});

test('license usage heartbeat does not write audit', function () use ($makeScope, $makeLicense, $makeUsage): void {
    $usage = $makeUsage($makeLicense($makeScope()));

    $auditCountBefore = Audit::query()
        ->where('auditable_type', $usage->getMorphClass())
        ->where('auditable_id', $usage->getKey())
        ->count();

    $usage->heartbeat();

    $auditCountAfter = Audit::query()
        ->where('auditable_type', $usage->getMorphClass())
        ->where('auditable_id', $usage->getKey())
        ->count();

    $this->assertSame(
        $auditCountBefore,
        $auditCountAfter,
        'Heartbeat updates 只改 last_seen_at，應被 $auditExclude + 空值守門員攔截。',
    );
});

test('license usage non heartbeat update writes audit', function () use ($makeScope, $makeLicense, $makeUsage): void {
    $usage = $makeUsage($makeLicense($makeScope()));

    $usage->update([
        'status' => UsageStatus::Revoked,
        'revoked_at' => now(),
    ]);

    $audit = Audit::query()
        ->where('auditable_type', $usage->getMorphClass())
        ->where('auditable_id', $usage->getKey())
        ->where('event', 'updated')
        ->firstOrFail();

    $this->assertArrayHasKey('status', $audit->new_values);
    $this->assertSame(UsageStatus::Revoked->value, $audit->new_values['status']);
});

test('vendor licensing audit log table is not written', function () use ($makeScope, $makeLicense): void {
    $license = $makeLicense($makeScope());
    $license->update(['max_usages' => 50]);

    $this->assertSame(
        0,
        DB::table('licensing_audit_logs')->count(),
        'Vendor 內建 audit 已關閉，licensing_audit_logs 應保持為空。',
    );
});
