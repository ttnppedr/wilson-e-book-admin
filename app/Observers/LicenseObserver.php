<?php

namespace App\Observers;

use Illuminate\Support\Facades\Crypt;
use App\Models\License;

class LicenseObserver
{
    /**
     * License 建立時，若缺少 key_hash 則自動產生暫時授權碼。
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

        // content key 不再複製到 meta，改由 activate 時即時查詢
        // License → Template → ContentEncryptionKey 關聯取得
    }
}
