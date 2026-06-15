<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\WordwallCategories\Pages\ManageWordwallCategories;
use App\Models\User;
use App\Models\Wordwall;
use App\Models\WordwallCategory;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
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

    public function test_edit_action_does_not_exist_on_row(): void
    {
        $category = WordwallCategory::factory()->create();

        Livewire::test(ManageWordwallCategories::class)
            ->assertActionDoesNotExist(TestAction::make('edit')->table($category));
    }
}
