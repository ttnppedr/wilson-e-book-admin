<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Artisan;

/**
 * Feature test helper：建立真實的 root + signing key 基礎設施。
 *
 * 使用情境：測試會走到**真實的 PASETO 簽發路徑**時（activate 簽 token、
 * validate 簽發驗證收據），即使 bearer 驗證已被 MocksLicenseTokenVerifier
 * mock 掉，issueToken() 仍需要真實金鑰存在，否則會 500。
 *
 * 用 database keystore：RefreshDatabase 會逐測試清空，不會污染
 * storage/app/licensing/keys（那是 prod 路徑）。
 */
trait CreatesLicensingKeys
{
    /**
     * 建立 root key 與一把有效期 365 天的 signing key。
     *
     * 兩個 Artisan 呼叫都斷言 exit code 0——金鑰建立失敗時若靜默略過，
     * 後續錯誤會以難以解讀的簽章例外形式出現在無關的斷言上。
     */
    protected function createLicensingKeys(string $passphrase = 'testing-keystore-passphrase'): void
    {
        config([
            'licensing.crypto.keystore.driver' => 'database',
            'licensing.crypto.keystore.passphrase' => $passphrase,
        ]);

        $this->assertSame(0, Artisan::call('licensing:keys:make-root'), 'make-root should succeed');
        $this->assertSame(
            0,
            Artisan::call('licensing:keys:issue-signing', ['--days' => 365]),
            'issue-signing should succeed',
        );
    }
}
