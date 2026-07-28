<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // 這裡建立的是固定帳密的測試帳號（UserFactory 的預設密碼是
        // 'password'）。專案文件從未指示在正式環境跑 seed，但「沒人會跑」
        // 不是控制措施 —— 一次誤觸 `php artisan migrate:fresh --seed` 就是
        // 一個可登入後台、能讀授權碼的帳號。
        if (! app()->environment(['local', 'testing'])) {
            $this->command?->warn('DatabaseSeeder：非 local/testing 環境，已略過。');

            return;
        }

        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
}
