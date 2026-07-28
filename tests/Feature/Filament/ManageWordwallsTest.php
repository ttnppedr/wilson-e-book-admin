<?php

use App\Filament\Resources\Wordwalls\Pages\ManageWordwalls;
use App\Models\User;
use App\Models\Wordwall;
use App\Models\WordwallCategory;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->admin = User::factory()->create();
    $this->actingAs($this->admin);
});

test('admin can render manage wordwalls page', function (): void {
    Livewire::test(ManageWordwalls::class)
        ->assertOk();
});

test('can list existing wordwalls in table', function (): void {
    $first = Wordwall::create(['resource_url' => 'https://wordwall.net/resource/1', 'sort' => 1]);
    $second = Wordwall::create(['resource_url' => 'https://wordwall.net/resource/2', 'sort' => 2]);

    Livewire::test(ManageWordwalls::class)
        ->assertCanSeeTableRecords([$first, $second]);
});

test('create first wordwall auto assigns sort to 1', function (): void {
    Livewire::test(ManageWordwalls::class)
        ->callAction(CreateAction::class, data: [
            'resource_url' => 'https://wordwall.net/resource/101',
        ])
        ->assertHasNoActionErrors();

    $this->assertDatabaseHas('wordwalls', [
        'resource_url' => 'https://wordwall.net/resource/101',
        'sort' => 1,
    ]);
});

test('create subsequent wordwall auto increments sort', function (): void {
    Wordwall::create(['resource_url' => 'https://wordwall.net/resource/100', 'sort' => 5]);

    Livewire::test(ManageWordwalls::class)
        ->callAction(CreateAction::class, data: [
            'resource_url' => 'https://wordwall.net/resource/200',
        ])
        ->assertHasNoActionErrors();

    $this->assertDatabaseHas('wordwalls', [
        'resource_url' => 'https://wordwall.net/resource/200',
        'sort' => 6,
    ]);
});

test('resource url must be required start with wordwall and unique', function (): void {
    Wordwall::create(['resource_url' => 'https://wordwall.net/resource/999', 'sort' => 1]);

    // required
    Livewire::test(ManageWordwalls::class)
        ->callAction(CreateAction::class, data: ['resource_url' => ''])
        ->assertHasActionErrors(['resource_url' => ['required']]);

    // regex:錯誤 domain
    Livewire::test(ManageWordwalls::class)
        ->callAction(CreateAction::class, data: ['resource_url' => 'https://example.com/resource/123'])
        ->assertHasActionErrors(['resource_url' => ['regex']]);

    // regex:http 而非 https
    Livewire::test(ManageWordwalls::class)
        ->callAction(CreateAction::class, data: ['resource_url' => 'http://wordwall.net/resource/123'])
        ->assertHasActionErrors(['resource_url' => ['regex']]);

    // 以 https://wordwall.net/ 開頭即通過格式檢查（不再限制路徑尾端為數字）
    Livewire::test(ManageWordwalls::class)
        ->callAction(CreateAction::class, data: ['resource_url' => 'https://wordwall.net/resource/abc'])
        ->assertHasNoActionErrors();

    // unique
    Livewire::test(ManageWordwalls::class)
        ->callAction(CreateAction::class, data: ['resource_url' => 'https://wordwall.net/resource/999'])
        ->assertHasActionErrors(['resource_url' => ['unique']]);
});

test('can assign category when creating wordwall', function (): void {
    $category = WordwallCategory::factory()->create();

    Livewire::test(ManageWordwalls::class)
        ->callAction(CreateAction::class, data: [
            'resource_url' => 'https://wordwall.net/resource/501',
            'wordwall_category_id' => $category->id,
        ])
        ->assertHasNoActionErrors();

    $this->assertDatabaseHas('wordwalls', [
        'resource_url' => 'https://wordwall.net/resource/501',
        'wordwall_category_id' => $category->id,
    ]);
});

test('can create wordwall without category', function (): void {
    Livewire::test(ManageWordwalls::class)
        ->callAction(CreateAction::class, data: [
            'resource_url' => 'https://wordwall.net/resource/502',
        ])
        ->assertHasNoActionErrors();

    $this->assertDatabaseHas('wordwalls', [
        'resource_url' => 'https://wordwall.net/resource/502',
        'wordwall_category_id' => null,
    ]);
});

test('category relationship resolves via foreign key', function (): void {
    // 守護 belongsTo 外鍵設定：必須對到 wordwall_category_id（非預設推導的 category_id），
    // 否則列表「遊戲分類」欄會把有分類的 wordwall 都顯示成「無分類」。
    $category = WordwallCategory::factory()->create();
    $wordwall = Wordwall::create([
        'resource_url' => 'https://wordwall.net/resource/800',
        'wordwall_category_id' => $category->id,
        'sort' => 1,
    ]);

    $resolved = $wordwall->fresh()->category;

    $this->assertNotNull($resolved, 'category() 關聯應解析得到分類');
    $this->assertSame($category->id, $resolved->id);
});

test('table displays assigned category name', function (): void {
    $category = WordwallCategory::factory()->create(['name' => '數學遊戲分類']);
    $wordwall = Wordwall::create([
        'resource_url' => 'https://wordwall.net/resource/801',
        'wordwall_category_id' => $category->id,
        'sort' => 1,
    ]);

    Livewire::test(ManageWordwalls::class)
        ->assertTableColumnStateSet('category.name', '數學遊戲分類', record: $wordwall);
});

test('can delete wordwall via row action', function (): void {
    $wordwall = Wordwall::create([
        'resource_url' => 'https://wordwall.net/resource/300',
        'sort' => 1,
    ]);

    Livewire::test(ManageWordwalls::class)
        ->callAction(TestAction::make(DeleteAction::class)->table($wordwall));

    $this->assertDatabaseMissing('wordwalls', ['id' => $wordwall->id]);
});

test('can edit category via row action', function (): void {
    $original = WordwallCategory::factory()->create();
    $target = WordwallCategory::factory()->create();
    $wordwall = Wordwall::create([
        'resource_url' => 'https://wordwall.net/resource/600',
        'wordwall_category_id' => $original->id,
        'sort' => 1,
    ]);

    Livewire::test(ManageWordwalls::class)
        ->callAction(
            TestAction::make(EditAction::class)->table($wordwall),
            data: ['wordwall_category_id' => $target->id],
        )
        ->assertHasNoActionErrors();

    $this->assertDatabaseHas('wordwalls', [
        'id' => $wordwall->id,
        'resource_url' => 'https://wordwall.net/resource/600',
        'wordwall_category_id' => $target->id,
    ]);
});

test('can clear category via row action', function (): void {
    $category = WordwallCategory::factory()->create();
    $wordwall = Wordwall::create([
        'resource_url' => 'https://wordwall.net/resource/601',
        'wordwall_category_id' => $category->id,
        'sort' => 1,
    ]);

    Livewire::test(ManageWordwalls::class)
        ->callAction(
            TestAction::make(EditAction::class)->table($wordwall),
            data: ['wordwall_category_id' => null],
        )
        ->assertHasNoActionErrors();

    $this->assertDatabaseHas('wordwalls', [
        'id' => $wordwall->id,
        'wordwall_category_id' => null,
    ]);
});

test('resource url is locked on edit', function (): void {
    $wordwall = Wordwall::create([
        'resource_url' => 'https://wordwall.net/resource/700',
        'sort' => 1,
    ]);

    // 網址欄位在編輯時為 disabled、不會 dehydrate，即使送入新值也不會被寫回。
    Livewire::test(ManageWordwalls::class)
        ->callAction(
            TestAction::make(EditAction::class)->table($wordwall),
            data: ['resource_url' => 'https://wordwall.net/resource/999'],
        );

    $this->assertDatabaseHas('wordwalls', [
        'id' => $wordwall->id,
        'resource_url' => 'https://wordwall.net/resource/700',
    ]);
});
