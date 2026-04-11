<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Wordwalls\Pages\ManageWordwalls;
use App\Models\User;
use App\Models\Wordwall;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ManageWordwallsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->actingAs($this->admin);
    }

    public function test_admin_can_render_manage_wordwalls_page(): void
    {
        Livewire::test(ManageWordwalls::class)
            ->assertOk();
    }

    public function test_can_list_existing_wordwalls_in_table(): void
    {
        $first = Wordwall::create(['resource_url' => 'https://wordwall.net/resource/1', 'sort' => 1]);
        $second = Wordwall::create(['resource_url' => 'https://wordwall.net/resource/2', 'sort' => 2]);

        Livewire::test(ManageWordwalls::class)
            ->assertCanSeeTableRecords([$first, $second]);
    }

    public function test_create_first_wordwall_auto_assigns_sort_to_1(): void
    {
        Livewire::test(ManageWordwalls::class)
            ->callAction(CreateAction::class, data: [
                'resource_url' => 'https://wordwall.net/resource/101',
            ])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('wordwalls', [
            'resource_url' => 'https://wordwall.net/resource/101',
            'sort' => 1,
        ]);
    }

    public function test_create_subsequent_wordwall_auto_increments_sort(): void
    {
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
    }

    public function test_resource_url_must_be_required_match_wordwall_format_and_unique(): void
    {
        Wordwall::create(['resource_url' => 'https://wordwall.net/resource/999', 'sort' => 1]);

        // required
        Livewire::test(ManageWordwalls::class)
            ->callAction(CreateAction::class, data: ['resource_url' => ''])
            ->assertHasActionErrors(['resource_url' => ['required']]);

        // regex:錯誤 domain
        Livewire::test(ManageWordwalls::class)
            ->callAction(CreateAction::class, data: ['resource_url' => 'https://example.com/resource/123'])
            ->assertHasActionErrors(['resource_url' => ['regex']]);

        // regex:路徑尾端非數字
        Livewire::test(ManageWordwalls::class)
            ->callAction(CreateAction::class, data: ['resource_url' => 'https://wordwall.net/resource/abc'])
            ->assertHasActionErrors(['resource_url' => ['regex']]);

        // regex:http 而非 https
        Livewire::test(ManageWordwalls::class)
            ->callAction(CreateAction::class, data: ['resource_url' => 'http://wordwall.net/resource/123'])
            ->assertHasActionErrors(['resource_url' => ['regex']]);

        // unique
        Livewire::test(ManageWordwalls::class)
            ->callAction(CreateAction::class, data: ['resource_url' => 'https://wordwall.net/resource/999'])
            ->assertHasActionErrors(['resource_url' => ['unique']]);
    }

    public function test_can_delete_wordwall_via_row_action(): void
    {
        $wordwall = Wordwall::create([
            'resource_url' => 'https://wordwall.net/resource/300',
            'sort' => 1,
        ]);

        Livewire::test(ManageWordwalls::class)
            ->callAction(TestAction::make(DeleteAction::class)->table($wordwall));

        $this->assertDatabaseMissing('wordwalls', ['id' => $wordwall->id]);
    }

    public function test_edit_action_does_not_exist_on_row(): void
    {
        $wordwall = Wordwall::create([
            'resource_url' => 'https://wordwall.net/resource/400',
            'sort' => 1,
        ]);

        Livewire::test(ManageWordwalls::class)
            ->assertActionDoesNotExist(TestAction::make('edit')->table($wordwall));
    }
}
