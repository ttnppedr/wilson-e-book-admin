<?php

namespace App\Console\Commands;

use App\Models\ContentEncryptionKey;
use Illuminate\Console\Command;

class ContentKeyCreateCommand extends Command
{
    protected $signature = 'content-key:create {name : 金鑰名稱（例如 english-v1）}';

    protected $description = '建立新的素材加密金鑰';

    public function handle(): int
    {
        $name = $this->argument('name');

        if (ContentEncryptionKey::where('name', $name)->exists()) {
            $this->error("名稱「{$name}」已存在。");

            return self::FAILURE;
        }

        $plainKey = ContentEncryptionKey::generateKey();

        $record = ContentEncryptionKey::create([
            'name' => $name,
            'encrypted_key' => $plainKey,
        ]);

        $this->info("已建立加密金鑰「{$record->name}」：");
        $this->newLine();
        $this->line("  <fg=green>{$plainKey}</>");
        $this->newLine();
        $this->warn('請將此金鑰提供給 App 開發端，用於加密該版本的素材。');

        return self::SUCCESS;
    }
}
