<?php

namespace App\Observers;

use Illuminate\Support\Facades\Crypt;
use LucaLongo\Licensing\Models\License;

class LicenseObserver
{
    /**
     * License 建立時，從 Scope 或 Template 的 meta 複製 content_key。
     * 若來源未設定 content_key，則自動產生一把新的 AES-256 金鑰。
     */
    public function creating(License $license): void
    {
        // vendor 的 createFromTemplate 不設 key_hash，但 DB 欄位是 NOT NULL。
        // 產生暫時的 key_hash，Filament CreateLicense page 會在 afterCreate 中覆寫為正式金鑰。
        if (empty($license->key_hash)) {
            $tempKey = License::generateKey();
            $license->key_hash = License::hashKey($tempKey);

            $meta = $license->meta ? (array) $license->meta : [];
            if (config('licensing.key_management.retrieval_enabled', true)) {
                $meta['encrypted_key'] = Crypt::encryptString($tempKey);
            }
            $license->meta = $meta;
        }

        $meta = $license->meta ? (array) $license->meta : [];
        if (empty($meta['content_key'])) {
            $meta['content_key'] = $this->resolveContentKey($license);
            $license->meta = $meta;
        }
    }

    private function resolveContentKey(License $license): string
    {
        // 從 .env 的 CONTENT_ENCRYPTION_KEY 讀取（必須與 App 建置 APK 時加密素材的 key 一致）
        $configKey = config('licensing.content_encryption_key');
        if (! empty($configKey)) {
            return $configKey;
        }

        // 其次從 Template meta 取得
        if ($license->template_id && $license->template) {
            $templateMeta = $license->template->meta ? (array) $license->template->meta : [];
            if (! empty($templateMeta['content_key'])) {
                return $templateMeta['content_key'];
            }
        }

        // 其次從 Scope meta 取得
        if ($license->license_scope_id && $license->scope) {
            $scopeMeta = $license->scope->meta ? (array) $license->scope->meta : [];
            if (! empty($scopeMeta['content_key'])) {
                return $scopeMeta['content_key'];
            }
        }

        // 都沒有則產生隨機值（僅限開發環境，正式環境應設定 CONTENT_ENCRYPTION_KEY）
        return substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(48))), 0, 43);
    }
}
