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
