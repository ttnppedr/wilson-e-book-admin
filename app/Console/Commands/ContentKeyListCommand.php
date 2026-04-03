<?php

namespace App\Console\Commands;

use App\Models\ContentEncryptionKey;
use Illuminate\Console\Command;

class ContentKeyListCommand extends Command
{
    protected $signature = 'content-key:list';

    protected $description = '列出所有素材加密金鑰';

    public function handle(): int
    {
        $keys = ContentEncryptionKey::orderBy('id')->get();

        if ($keys->isEmpty()) {
            $this->info('尚未建立任何加密金鑰。使用 content-key:create {name} 建立。');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', '名稱', '金鑰', '建立時間'],
            $keys->map(fn (ContentEncryptionKey $key) => [
                $key->id,
                $key->name,
                $key->encrypted_key,
                $key->created_at->format('Y-m-d H:i:s'),
            ]),
        );

        return self::SUCCESS;
    }
}
