<?php

namespace App\Observers;

use App\Models\ContentEncryptionKey;
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

    private function resolveContentKey(License $license): ?string
    {
        // 從 Template 的 content_encryption_key_id 查詢
        if ($license->template_id && $license->template) {
            $keyId = $license->template->content_encryption_key_id;
            if ($keyId) {
                $contentKey = ContentEncryptionKey::find($keyId);
                if ($contentKey) {
                    return $contentKey->encrypted_key;
                }
            }
        }

        return null;
    }
}
