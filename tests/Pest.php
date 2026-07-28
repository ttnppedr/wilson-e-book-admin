<?php

use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| 以 Pest 語法（closure）撰寫的測試會綁定到這裡指定的 TestCase。
|
| 只綁定 Feature 目錄：tests/Unit 底下現有測試（ContentKeyWrapperTest）刻意直接繼承
| PHPUnit\Framework\TestCase，不啟動 Laravel app，維持純粹的單元測試。Unit 目錄因此
| 沿用 Pest 預設的 PHPUnit\Framework\TestCase。
|
| 這裡不全域套用 RefreshDatabase：各測試檔自行以 uses(RefreshDatabase::class) 宣告是否
| 需要資料庫，全域套用會讓不需要 DB 的測試平白付出 migration 成本。
|
| 註：全部測試檔皆為 Pest closure 語法。請勿新增 PHPUnit class 型測試——TIA
| （vendor/bin/pest --tia）遇到 class 型測試會整個停用。
|
*/

pest()->extend(TestCase::class)->in('Feature');

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| 跨測試檔共用的 helper。只放「多個檔案都需要、且與單一測試主題無關」的東西；
| 單一檔案內部使用的 helper 留在該檔的檔案級 closure。
|
*/

/**
 * 產生一組全新的 X25519 client ephemeral public key（base64）。
 *
 * activate 端點要求 client 送出合法的 32 bytes X25519 公鑰，否則會在
 * INVALID_EPHEMERAL_KEY 前置檢查就被擋下、進不到要測的業務邏輯。
 */
function freshClientPublicKeyB64(): string
{
    return base64_encode(sodium_crypto_box_publickey(sodium_crypto_box_keypair()));
}
