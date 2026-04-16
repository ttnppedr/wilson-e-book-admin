<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class LicensingPassphraseGenerateCommand extends Command
{
    protected $signature = 'licensing-passphrase:generate
        {--show : 僅顯示產生的 passphrase，不寫入 .env}
        {--force : 強制覆寫既有的 LICENSING_KEY_PASSPHRASE}';

    protected $description = '產生隨機的 LICENSING_KEY_PASSPHRASE 並寫入 .env';

    public function handle(): int
    {
        $passphrase = base64_encode(random_bytes(32));

        if ($this->option('show')) {
            $this->line($passphrase);

            return self::SUCCESS;
        }

        $envPath = $this->laravel->environmentFilePath();

        if (! is_file($envPath)) {
            $this->error(".env 檔案不存在：{$envPath}");

            return self::FAILURE;
        }

        $content = file_get_contents($envPath);
        $currentValue = $this->extractCurrentValue($content);

        if ($currentValue !== null && $currentValue !== '') {
            if (! $this->option('force')) {
                $this->error('LICENSING_KEY_PASSPHRASE 已有值。覆寫會導致既有 root 金鑰無法解密，需重新執行 licensing:keys:make-root。若確認要覆寫，請加上 --force。');

                return self::FAILURE;
            }

            if (! $this->confirm('覆寫既有 passphrase 會讓既有 root 私鑰永久無法解密，確認繼續？', false)) {
                return self::FAILURE;
            }
        }

        $updated = $this->replaceOrAppend($content, $passphrase);

        file_put_contents($envPath, $updated);

        $this->info('已寫入 LICENSING_KEY_PASSPHRASE：');
        $this->newLine();
        $this->line("  <fg=green>{$passphrase}</>");
        $this->newLine();
        $this->warn('請立即將此值備份到密碼管理器或離線儲存。遺失後 root 私鑰將永久無法解密。');

        return self::SUCCESS;
    }

    private function extractCurrentValue(string $content): ?string
    {
        if (! preg_match('/^LICENSING_KEY_PASSPHRASE=([^\r\n#]*)/m', $content, $matches)) {
            return null;
        }

        return trim($matches[1], " \t\"'");
    }

    private function replaceOrAppend(string $content, string $passphrase): string
    {
        $replacement = 'LICENSING_KEY_PASSPHRASE="'.$passphrase.'"';

        if (preg_match('/^LICENSING_KEY_PASSPHRASE=.*$/m', $content)) {
            return preg_replace('/^LICENSING_KEY_PASSPHRASE=.*$/m', $replacement, $content);
        }

        return rtrim($content, "\n")."\n\n".$replacement."\n";
    }
}
