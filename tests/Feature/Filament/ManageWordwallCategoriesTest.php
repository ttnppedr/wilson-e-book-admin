<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\WordwallCategories\Pages\ManageWordwallCategories;
use App\Models\User;
use App\Models\Wordwall;
use App\Models\WordwallCategory;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ManageWordwallCategoriesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // 模擬生產環境的 S3 相容 disk（有 url 設定），FileUpload 與 ImageColumn 都用預設 disk。
        config(['filesystems.default' => 's3']);
        Storage::fake('s3', ['url' => 'https://cdn.test/wordwall']);

        $this->admin = User::factory()->create();
        $this->actingAs($this->admin);
    }

    public function test_admin_can_render_manage_page(): void
    {
        Livewire::test(ManageWordwallCategories::class)
            ->assertOk();
    }

    public function test_can_list_existing_categories_in_table(): void
    {
        $first = WordwallCategory::factory()->create(['sort' => 1]);
        $second = WordwallCategory::factory()->create(['sort' => 2]);

        Livewire::test(ManageWordwallCategories::class)
            ->assertCanSeeTableRecords([$first, $second]);
    }

    public function test_create_first_category_uploads_image_and_assigns_sort_to_1(): void
    {
        Livewire::test(ManageWordwallCategories::class)
            ->callAction(CreateAction::class, data: [
                'name' => '數學',
                'image_path' => [UploadedFile::fake()->image('math.png')],
            ])
            ->assertHasNoActionErrors();

        $category = WordwallCategory::query()->where('name', '數學')->first();
        $this->assertNotNull($category);
        $this->assertSame(1, $category->sort);
        $this->assertNotNull($category->image_path);
        Storage::disk('s3')->assertExists($category->image_path);
    }

    public function test_create_subsequent_category_auto_increments_sort(): void
    {
        WordwallCategory::factory()->create(['sort' => 5]);

        Livewire::test(ManageWordwallCategories::class)
            ->callAction(CreateAction::class, data: [
                'name' => '英文',
                'image_path' => [UploadedFile::fake()->image('english.png')],
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('wordwall_categories', [
            'name' => '英文',
            'sort' => 6,
        ]);
    }

    public function test_name_is_required(): void
    {
        Livewire::test(ManageWordwallCategories::class)
            ->callAction(CreateAction::class, data: [
                'name' => '',
                'image_path' => [UploadedFile::fake()->image('x.png')],
            ])
            ->assertHasActionErrors(['name' => ['required']]);
    }

    public function test_image_is_required(): void
    {
        Livewire::test(ManageWordwallCategories::class)
            ->callAction(CreateAction::class, data: [
                'name' => '無圖分類',
            ])
            ->assertHasActionErrors(['image_path' => ['required']]);
    }

    public function test_image_must_be_an_image_file(): void
    {
        Livewire::test(ManageWordwallCategories::class)
            ->callAction(CreateAction::class, data: [
                'name' => '非圖分類',
                'image_path' => [UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf')],
            ])
            ->assertHasActionErrors(['image_path']);
    }

    public function test_image_rejects_files_over_max_size(): void
    {
        // maxSize(2048) = 2MB 上限，3MB 應被拒。
        Livewire::test(ManageWordwallCategories::class)
            ->callAction(CreateAction::class, data: [
                'name' => '超大圖分類',
                'image_path' => [UploadedFile::fake()->image('big.png')->size(3000)],
            ])
            ->assertHasActionErrors(['image_path']);
    }

    public function test_name_must_be_unique(): void
    {
        WordwallCategory::factory()->create(['name' => '重複名稱']);

        Livewire::test(ManageWordwallCategories::class)
            ->callAction(CreateAction::class, data: [
                'name' => '重複名稱',
                'image_path' => [UploadedFile::fake()->image('dup.png')],
            ])
            ->assertHasActionErrors(['name' => ['unique']]);
    }

    public function test_can_delete_category_via_row_action(): void
    {
        $category = WordwallCategory::factory()->create();

        Livewire::test(ManageWordwallCategories::class)
            ->callAction(TestAction::make(DeleteAction::class)->table($category));

        $this->assertDatabaseMissing('wordwall_categories', ['id' => $category->id]);
    }

    public function test_deleting_category_nulls_wordwall_foreign_key(): void
    {
        // 刪除分類時，底下遊戲的 wordwall_category_id 應被設為 null（變成未分類）而非連帶刪除。
        $category = WordwallCategory::factory()->create();
        $wordwall = Wordwall::factory()->create(['wordwall_category_id' => $category->id]);

        Livewire::test(ManageWordwallCategories::class)
            ->callAction(TestAction::make(DeleteAction::class)->table($category));

        $this->assertDatabaseHas('wordwalls', [
            'id' => $wordwall->id,
            'wordwall_category_id' => null,
        ]);
    }

    public function test_deleting_category_removes_orphan_image_file(): void
    {
        $category = WordwallCategory::factory()->create(['image_path' => 'wordwall-categories/orphan.png']);
        Storage::disk('s3')->put($category->image_path, 'fake-bytes');
        Storage::disk('s3')->assertExists($category->image_path);

        Livewire::test(ManageWordwallCategories::class)
            ->callAction(TestAction::make(DeleteAction::class)->table($category));

        Storage::disk('s3')->assertMissing($category->image_path);
    }

    public function test_reordering_updates_sort_column(): void
    {
        $a = WordwallCategory::factory()->create(['sort' => 1]);
        $b = WordwallCategory::factory()->create(['sort' => 2]);
        $c = WordwallCategory::factory()->create(['sort' => 3]);

        Livewire::test(ManageWordwallCategories::class)
            ->call('reorderTable', [$c->getKey(), $a->getKey(), $b->getKey()]);

        $this->assertSame(1, $c->fresh()->sort);
        $this->assertSame(2, $a->fresh()->sort);
        $this->assertSame(3, $b->fresh()->sort);
    }

    public function test_can_edit_name_via_row_action(): void
    {
        $path = 'wordwall-categories/keep.png';
        $category = WordwallCategory::factory()->create(['name' => '舊名稱', 'image_path' => $path]);
        Storage::disk('s3')->put($path, 'fake-bytes');

        Livewire::test(ManageWordwallCategories::class)
            ->callAction(
                TestAction::make(EditAction::class)->table($category),
                data: ['name' => '新名稱'],
            )
            ->assertHasNoActionErrors();

        // 只改名稱：image_path 不變、舊圖檔保留（未換圖不應觸發清理）。
        $this->assertDatabaseHas('wordwall_categories', [
            'id' => $category->id,
            'name' => '新名稱',
            'image_path' => $path,
        ]);
        Storage::disk('s3')->assertExists($path);
    }

    public function test_replacing_image_removes_previous_file(): void
    {
        // 守護 model 的 updated 事件：換圖（image_path 變更）時應刪除被取代的舊圖、保留新圖，
        // 避免在 S3 上累積孤兒檔。直接走 model 層以精準觸發事件，不依賴 FileUpload 的上傳模擬。
        $oldPath = 'wordwall-categories/old.png';
        $newPath = 'wordwall-categories/new.png';
        $category = WordwallCategory::factory()->create(['image_path' => $oldPath]);
        Storage::disk('s3')->put($oldPath, 'old-bytes');
        Storage::disk('s3')->put($newPath, 'new-bytes');

        $category->update(['image_path' => $newPath]);

        Storage::disk('s3')->assertMissing($oldPath);
        Storage::disk('s3')->assertExists($newPath);
    }

    public function test_updating_non_image_field_keeps_image_file(): void
    {
        // 反向守護：只改其他欄位（image_path 未變）時，不應誤刪圖片檔。
        $path = 'wordwall-categories/stable.png';
        $category = WordwallCategory::factory()->create(['image_path' => $path]);
        Storage::disk('s3')->put($path, 'bytes');

        $category->update(['name' => '改個名字']);

        Storage::disk('s3')->assertExists($path);
    }

    public function test_editing_name_to_another_existing_name_is_rejected(): void
    {
        WordwallCategory::factory()->create(['name' => '已存在']);
        $category = WordwallCategory::factory()->create(['name' => '可改的']);

        Livewire::test(ManageWordwallCategories::class)
            ->callAction(
                TestAction::make(EditAction::class)->table($category),
                data: ['name' => '已存在'],
            )
            ->assertHasActionErrors(['name' => ['unique']]);
    }
}
