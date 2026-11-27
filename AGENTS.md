# AGENTS.md

This file provides guidance to Codex (Codex.ai/code) when working with code in this repository.

## 語言規則

- 所有回應內容、commit 訊息、PR 描述都必須使用**繁體中文台灣用語**
- 技術術語和程式碼識別碼維持原文

## 專案概述

Wilson 電子書管理後台，基於 Laravel 13 + Filament v5 建構，整合 `masterix21/laravel-licensing` 2.x 授權管理系統。主要功能為管理電子書產品的軟體授權（License Scope → License → License Usage）。

## 架構

- **管理面板**: Filament v5，路徑 `/admin`，設定在 `app/Providers/Filament/AdminPanelProvider.php`
- **授權系統**: `masterix21/laravel-licensing` 2.x + `laravel-licensing-filament-manager`，兩層架構：LicenseScope（產品/版本）→ License（個別授權）→ LicenseUsage（席位）。Vendor 2.0 重新引入 LicenseTemplate，但本專案刻意不使用（`license_templates` 表已刪除、`config('licensing.templates.enabled')` 設為 `false`）
- **Content Encryption Key**: 掛在 LicenseScope 上（`content_encryption_key_id`），每個產品/版本對應一把加密金鑰，啟用 API 透過 `License → Scope → CEK` 取得
- **授權 enum 翻譯**: vendor 的 `LicenseStatus` 透過 `app/Enums/LicenseStatusLabel.php`（實作 `HasLabel`/`HasColor`）翻譯顯示，在 `LicenseResource` 以 `formatStateUsing`/`SelectFilter` 套用（commit 06c611a 已移除舊的全域 `configureUsing` hack）
- **翻譯檔**: 套件有兩個翻譯 namespace（`laravel-licensing-filament-manager` 和 `licensing-filament-manager`），zh_TW 翻譯分別在 `lang/vendor/` 下對應的兩個目錄
- **時區**: 顯示用 `Asia/Taipei`（GMT+8），資料庫儲存為 UTC
- **Docker**: Sail 使用自訂 Dockerfile（`docker/8.5/Dockerfile`），已加入 `php8.5-gmp` 擴充套件
- **Pint**: 編輯 PHP 檔案後由 PostToolUse hook 自動逐檔格式化；手動整批格式化用 `vendor/bin/sail bin pint --dirty`
- **AGENTS.md**: Codex 用的 CLAUDE.md 副本，由 `bin/sync-agents-md.sh`（`composer agents-md`）從 CLAUDE.md 產生，只差第 1、3 行標題。**不要直接編輯 AGENTS.md**；改完 CLAUDE.md 或跑完 `boost:update` 後執行一次。由 `tests/Unit/AgentsMdSyncTest.php` 守護（`tests/Pest.php` 以 TIA watch 讓只改 Markdown 時也會重跑）
- **測試**: Pest 5，**39 個測試檔全部是 Pest closure 語法**（`test('...', function () {...})`）。`tests/Pest.php` 只把 `Tests\TestCase` 綁到 `Feature`；`Unit` 沿用 Pest 預設的 `PHPUnit\Framework\TestCase`，維持 `ContentKeyWrapperTest` 不啟動 Laravel app。**新測試一律寫 Pest 語法，不要退回 PHPUnit class**——只要有任何一個 class 型測試存在，`--tia` 就會整個停用（見下）。慣用轉換：`setUp` → `beforeEach`、`tearDown` → `afterEach`、`private helper` → 檔案級 closure（需 `$this` 者用 `$closure->call($this, ...)`）、`#[DataProvider]` → `dataset()` + `->with()`。斷言一律沿用 `$this->assertX()`，不必改寫成 `expect()`
- **測試效能**: 三種模式擇一。`vendor/bin/pest --tia`（**最快，日常首選**）：無變更 0.4s、改動一個 model 約 5s（只重跑受影響者、其餘 replay）；`composer test:parallel` 2.5s；完整序列 10.2s。TIA 靠執行時記錄的相依圖判斷影響範圍，**變更偵測是語意層級**——加空行/改排版不會觸發重跑，改 `Wordwall::category()` 的外鍵則精準牽動 63 個測試。首次或相依圖過期時會自動全跑重建（約 12s）。`--profile` 顯示負載分佈平均、無單一瓶頸（最慢 10 個僅佔 29%，最慢者為 `LicensePasetoIssuanceTest` 的真實 Ed25519 簽章，刻意不 mock），故逐個優化個別測試不會有回報。**`--dirty` 實測無效**（即使測試檔確有 git 變更仍跑滿全部、也不拋 `NoDirtyTestsFound`），直接用 `--tia` 取代它
- **測試工具鏈版本管理**: `phpunit/phpunit` 不在 `require-dev`（改由 pest 以 transitive 相依管理），避免 pest 的 `conflict` 約束讓 `outdated --direct` 掛著升不動的項目——Pest 慣例以 `conflict: phpunit/phpunit >X.Y.Z` 把 PHPUnit 釘在單一 patch 版（paratest 也會被連帶擋住），曾在 Pest 5.0.0–5.0.1 期間凍結過 PHPUnit 13.2.4（Pest 5.0.3 已解凍至 13.2.6）。日後 `composer outdated` 若再出現 phpunit/paratest 升不動，屬預期狀態、非設定損壞，解凍點是升級 Pest 本身
- **寬限期為 0 天**: 授權的到期日一直是 `expires_at`（判斷點從未改變），改變的是**到期後不再給寬限**。⚠️ 光把 `config('licensing.policies.grace_days')` 設為 0 **達不到**這個效果——vendor 的 `License::isInGracePeriod()` 只看 `status === Grace`、**完全不讀 grace_days**（讀天數的是另一支 `gracePeriodExpired()`），所以 status 為 Grace 的授權在 vendor 原本的 `isExpired() && ! isInGracePeriod()` 下仍會被放行。因此實作改從狀態機下手：`App\Models\License::transitionToGrace()` 覆寫為 no-op（授權永遠進不了 Grace），`guardLicenseState()` 直接看 `isExpired()`。config 的 `grace_days => 0` 只是讓 vendor 內殘留的 grace 計算立即為真，不是把關點
- **到期狀態收斂（三個判斷點）**: `expires_at` 是唯一權威（`isExpired()` 純日期比較），`status` 欄位只是給後台顯示／統計的鏡像。三處發現過期時**都會**呼叫 `transitionToExpired()` 把事實寫回 DB 並留 audit：`LicenseController::guardLicenseState()`（activate + validate 共用）、`EnsureLicenseUsable`（兩個 `v1/*` 內容端點）、`CheckLicenseExpirationsCommand`（兜底掃過期後再也沒人連線的授權）。`transitionToExpired()` 是**冪等**的（狀態白名單 + `isExpired()` 兩道 guard），所以放在每次請求路徑上安全——只有「過期後第一次」真的寫入。API 觸發時另記 `[api] license auto-expired` 到 `api` channel 作為來源追查依據；**刻意不靠 audit 的 `url` 欄位區分來源**，因為 owen-it 的 `UrlResolver` 只認 `App::runningInConsole()`，Pest 由 CLI 啟動 → 所有 feature test 的 audit url 都是 `console`，等於沒有測試守得住。`EXPIRABLE_STATUSES` 含 **Pending**（代表「建立後從未啟用」，不是管理者決定）；`activateIfPending()` 會擋掉「已過期的 Pending 授權被 activate」——vendor 的 `canActivate()` 只看 status、`activate()` 不看 `expires_at`，少了這道會寫出「今天啟用」的假 `activated_at`
- **排程與 CLI**: `licensing:check-expirations` 每日把過期 License 直接轉 expired（不經 grace；Grace 仍列在掃描條件內僅為收斂舊資料）（`routes/console.php`，受 `config/licensing.php` 的 `scheduler.check_expirations` 控制，`onOneServer`+`withoutOverlapping`）。**每次執行都會記一筆總結 `[licensing] check-expirations`（default channel，只含 `moved_to_expired` 數量），零異動也記**——否則「排程沒跑」與「跑了但沒東西到期」在事後都是零筆記錄、無從區分；寫在 `finally` 讓中途拋錯時仍留痕。被變更的 id 另以 `[licensing] check-expirations ids` 逐批列出（每行 `LOGGED_IDS_PER_LINE`＝200 個，帶 `batch` 序號）：**id 一個都不省略**，逾量是換行續寫而非截斷——單行塞上萬個 id 會被 log 管線切斷，那同樣會漏。零異動時不產生 ids 行。由 `command logs every changed id without dropping any`（205 筆跨兩行）守護。另有 `content-key:create`/`content-key:list`、`licensing:passphrase-generate` 等 CLI。Wordwall 與 WordwallCategory（遊戲分類，name + 圖片；與 Wordwall 為 nullable 一對多，刪分類時遊戲 FK `nullOnDelete`）皆非 audit 對象（model + Filament resource + `v1/wordwall-categories`、`v1/wordwall-categories/{id}/image` API），故可合法用 `reorderable('sort')`
- **App 診斷紀錄**: `DiagnosticSession`（`diagnostic_sessions`，App 一次啟動到結束）→ `DiagnosticEvent`（`diagnostic_events`，unique `(diagnostic_session_id, seq)`）。**兩者皆非 audit 對象**，事件以 `insertOrIgnore` 批次寫入、`diagnostics:prune` 以 mass delete 清除。時間欄位一律毫秒精度（`dateTime(…, 3)` + model 的 `$dateFormat = 'Y-m-d H:i:s.v'`；用預設格式存檔會把毫秒截掉）。`license_id`／`license_usage_id` 為 `nullOnDelete`——授權或席位刪除後紀錄仍留到保留期滿。**裝置時間與伺服器時間並存**：`started_at`／`first_event_at`／`last_event_at`／事件的 `occurred_at` 是裝置自報（時鐘可能停在過去或跑到未來），`last_received_at`／事件的 `received_at` 是伺服器收到的時間。⚠️ **清單預設排序與 `diagnostics:prune` 一律看 `last_received_at`**——改看裝置時間的話，時鐘停在過去的裝置紀錄隔天就被清掉、時鐘在未來的永遠清不掉且永遠排第一（由 `retention follows the server receive time and ignores the device clock` 守護）。寫入量刻意不設上限（單一 session／單一裝置皆無事件數限制），靠保留期控制總量。後台在 `app/Filament/Resources/DiagnosticSessions/`（導覽「授權管理 → 診斷紀錄」，slug `diagnostics`，唯讀＋可刪單筆）：清單頁可依授權／環境／有無錯誤／指紋／日期篩選，`LicenseUsageResource`（列表列動作與檢視頁）與授權檢視頁各有入口帶篩選連過去；檢視頁是**時間軸**（`resources/views/filament/diagnostics/timeline.blade.php`，資料由 `App\Services\DiagnosticTimelineBuilder` 整理），依 `seq` 排序（`occurred_at` 是裝置時間、可能不準）、一次載入最新 2000 筆並可往前載入；header 有「同裝置上一個／下一個 session」（同 fingerprint、依 `started_at` 再以 id 決定，沒有時停用），因為現場第一個動作常是重開 App、一個問題會跨好幾個 session。⚠️ **事件內容由裝置經免驗證端點自報，一律視為不可信輸入**：blade 只能用 `{{ }}` 輸出、不可把事件內容放進 inline script 或 Alpine 運算式；類別鍵進 class／CSS selector 前由 builder 再次限制為 `[a-z_]`。時間軸樣式寫在該 blade 的 `<style>` 內並只用 Filament 輸出的 CSS 變數（專案沒有編譯自訂 Tailwind theme，新寫的 utility class 不會有 CSS）；篩選由 Alpine 切換根元素 class、實際隱藏交給 CSS，不逐列綁定也不打 Livewire 往返。由 `tests/Feature/Filament/DiagnosticSessionsTest.php` 守護（含 XSS escape 斷言）。`diagnostics:prune`（保留三個月，**一律依伺服器時間**：刪除 `last_received_at` 早於三個月的 session 與其事件，另從仍在上傳的長 session 刪除 `received_at` 早於三個月的事件並重算該 session 的計數欄位；`--days=` 可改以天數指定）每日 03:00（`APP_TIMEZONE`）排程，`diagnostic_events.received_at` 有索引供它使用。本機示範資料：`php artisan db:seed --class=DiagnosticDemoSeeder`（僅 `local` 環境會執行）

## API 層

對外授權/內容 API。vendor `licensing.api.enabled = false`，**所有路由手動註冊在 `routes/api.php`**：

- `POST api/licensing/v1/activate` — `Api\LicenseController::activate`，ECDH(X25519) content key wrapping + 簽發 PASETO token。Throttle `licensing-activate`
- `POST api/licensing/v1/validate` — `Api\ValidateController`，heartbeat 合併，需回帶 activate 簽發的 PASETO token（`VerifyBearerToken`）。Throttle `licensing-validate`
- `GET api/v1/wordwall-categories` — `Api\WordwallCategoryController`，**巢狀**回傳每個分類（`id, name, image_url, sort`）+ 其底下依 `sort` 排序的 wordwalls（`id, sort, resource_url`），供 App 遊戲頁兩層導覽（分類 → 該分類的遊戲）。需 bearer token + ETag（`SetResponseEtag`）。Throttle `api-wordwall`。兩層 query 皆加 `orderBy('id')` tie-break 確保 ETag 穩定。圖片 `image_url` **不直接給 S3 網址**，而由 `WordwallCategory::image_url` accessor 產生指向下方圖片轉址端點的**穩定 URL**（帶 `v=md5(image_path)` 片段，換圖時即變動以汰換 client 快取、同時不外洩原始儲存路徑）——刻意不放 presigned URL，因其簽章/時間戳每次不同會讓內容型 ETag 每次失效
- `GET api/v1/wordwall-categories/{id}/image` — `Api\WordwallCategoryImageController`，S3 物件**全程 private**：請求當下以 `Storage::temporaryUrl()` 簽發短效（15 分）presigned 網址並 **302 轉址**，故 bucket 可關閉公開存取（不可列舉/盜連），授權從 bucket 層上移到應用層。需 bearer token（`VerifyBearerToken`，**App 載圖須帶 `Authorization` header**）。**不掛** `SetResponseEtag`（回應為轉址、非可快取 body）。Throttle `api-wordwall`。無圖回統一格式 `IMAGE_NOT_FOUND` 404。後台 Filament 的 `FileUpload`/`ImageColumn` 亦設 `visibility('private')`，預覽由 Filament 對 private disk 自動簽發 `temporaryUrl`（過期對齊整點以利快取）。⚠️ **`ImageColumn` 必須加 `->checkFileExistence(false)`**：它預設 `shouldCheckFileExistence = true`，會在產生預覽網址前先 `$storage->exists()`——對 s3 disk 是一次**真實 HeadObject 網路往返，且逐列序列化**。`WordwallCategoryResource` 的表格 `->paginated(false)`（因 `reorderable('sort')` 與分頁衝突）又有 `->searchable()`（每個按鍵一次 Livewire 往返、整表重繪），成本因此是「分類數 × S3 RTT × 每次互動」。本機 MinIO 同主機約 1.3ms/列察覺不到，正式站對真實 S3 是 15–40ms/列——120 筆本機 264ms、正式站數秒。`temporaryUrl()` 本身是本地 HMAC 簽章（0.3ms）不是瓶頸。由 `ManageWordwallCategoriesTest` 的 `image column does not check file existence per row` 守護

- `POST api/licensing/v1/diagnostics` — `Api\DiagnosticsController`，App 上傳操作事件日誌（一批 1–500 筆，body 上限約 512 KB→超過回 413 `PAYLOAD_TOO_LARGE`），供後台「診斷紀錄」時間軸事後追查現場問題。Throttle `licensing-diagnostics`。**刻意不掛 `VerifyBearerToken`**（要診斷的錯誤常發生在尚未授權的畫面；帶了 `Authorization` 也不驗），代價是它是個免驗證的寫入端點，因此由 `App\Services\DiagnosticRecorder` 把關：①`fingerprint` 必須對得上任一筆 `license_usages.usage_fingerprint`（同指紋多筆取 `last_seen_at` 最新者），對不上**照樣回 202 但不保存**（`accepted: 0`、`recognized: false`）；②session 建立後即綁定當時的指紋，別的指紋不能往同一個 `session_id` 追加；③單筆 `data` 編碼後超過 4096 bytes 換成 `{"_truncated": true}`，不整批拒收。冪等鍵為 `(session, seq)`（App 會重試），`accepted` 是**實際新寫入**筆數，session 的計數欄位只依新寫入的事件累加。**驗證分兩段**：先驗頂層欄位並查指紋，對不上就直接回 202、不驗事件內容；且交給 validator 的資料先經 `shallow()` 把巢狀陣列換成空陣列（事件 `data` 的內容不讓 validator 走訪）。原因是節流鍵含呼叫端自填的指紋（換指紋就有新額度；刻意不加 IP 上限，因為一所學校多台裝置共用對外 IP），而 Laravel validator 會走訪拿到的每個巢狀節點——整包原始輸入直接驗時，500 KB 深層巢狀請求實測約 1 秒，改後陌生指紋約 0.14 秒、一般大小約 10 ms。**每次上傳都記一行 `[api] diagnostics upload` 到 `api` channel**（`outcome`：`stored`／`unknown_fingerprint`／`fingerprint_mismatch`／`duplicate_only`，另含 `received`、`accepted`、`duplicates`、`truncated`、seq 範圍、對到的授權與席位 id）——回應的 `accepted: 0` 有三種成因，只能靠這行分辨；由 `every upload outcome is written to the api log with its reason` 守護。事件 `data` **原樣保存、不做遮蔽**（需求是完整記錄）。回應 `202 {success, accepted, recognized}`——`recognized` 只在指紋找不到席位時為 `false`（已儲存、整批重送、session 指紋不符皆為 `true`），App 靠它分辨「沒人收下、要保留本機紀錄」與「已送達」（例如席位在後台刪除後）；**欄位名稱與語意是與 App 約定的合約，不可改名**；驗證失敗 422 `VALIDATION_FAILED`（`details` 最多列 20 個欄位）。⚠️ **這支不得回 401／404**（App 對某些 401／404 會清除本機授權）。`cat` 只限制格式 `[a-z_]{1,32}`、不寫死 enum——App 新增類別時後端不必改版，後台對未知類別用預設樣式。App 送的時間是 UTC，寫入前先轉成 app timezone（Eloquent 以 app timezone 解讀時間欄位、寫入時不做轉換；`APP_TIMEZONE` 是 `Asia/Taipei`，少了這步會差 8 小時）。由 `tests/Feature/Api/DiagnosticsUploadTest.php` 守護

**Vendor 覆寫（升級 gotcha）**: 三處**複製自 vendor 並就地插入自訂邏輯**的覆寫——`Api\LicenseController extends` vendor `BaseLicenseController`（`activate()`）、`ValidateController extends LicenseController`（`validateLicense()`）、`App\Services\WilsonPasetoTokenService extends` vendor `PasetoTokenService`（`issue()`，PASETO 簽章核心；與 parent 有**三處**差異，皆已就地標註「新增」——`$expiration` 以 `expires_at` 夾住上界、extra_claims 合併、`licensable_id` 轉字串（App 以 `String?` 解析）；vendor 2.3.0 起 parent 也有自己的 `claims` option，但在本覆寫中不生效，呼叫端一律用 `extra_claims`）。方法註解皆標註「對齊版本：vendor 2.3.0」。

⚠️ **model binding 陷阱**：`Api\LicenseController::findLicense()` 另有一處**非複製型**覆寫，改用 `config('licensing.models.license')` 查詢。原因是 vendor 的 `Licensing::findByKey()`（`src/Licensing.php:19-22`）**硬呼叫** `LucaLongo\Licensing\Models\License::findByKey()`，不讀 models config（vendor 其他地方如 `LicenseUsage::license()`、`CheckExpirationsCommand` 都有讀，唯獨這條沒有）。少了覆寫，activate / validate 會拿到 **vendor model**，`App\Models\License` 的每項覆寫靜默失效：`implements Auditable`（**API 對 License 的寫入完全不留 audit**）、`EnforcesAuditEvents`、`transitionToExpired()`（落回 vendor 版要求先進 Grace，永遠 no-op）。失效不拋錯、無徵兆。由 `tests/Feature/Api/LicenseModelBindingTest.php` 守護（含型別斷言 + 「API 觸發的 pending → active 必須留 audit」行為斷言）。升級後若 vendor 改為讀 config，可拿掉覆寫——但拿掉前先讓該測試轉綠。**升級 `masterix21/laravel-licensing` 時務必逐一重新對齊 parent 的最新實作**——例如 vendor 自 2.0.1 起把 signing key 建構改為「取前 32 bytes seed 經 `AsymmetricSecretKey::v4()` 重新推導 keypair」（`buildSecretKey()`），以通過 paragonie/paseto 3.5 的 v4 misuse-resistance 檢查；此修正需同步移植，否則輪替到特定金鑰時 `issue()` 會簽章失敗。此真實簽發路徑由 `tests/Feature/Api/LicensePasetoIssuanceTest.php`（真實 root/signing key、不 mock，含 activate 端點端對端解 content key）守護；其餘 API 測試以 `MocksLicenseTokenVerifier` mock 掉 verifier 故碰不到此路徑，另有 `tests/e2e_activate_verify.php` 手動 E2E 腳本。

**升級守護（已出貨 App）**: 升級任何套件後下列兩檔必須全綠。`LegacyTokenCompatibilityTest` 重播 `tests/Fixtures/legacy_token_fixture.json`（升級前程式碼簽出的 token + 含 passphrase 加密私鑰的 `licensing_keys` 原始 row），守「已發出的 token 仍可驗章、DB 既存私鑰仍可解密簽章」——`LicensePasetoIssuanceTest` 是同版往返，vendor 若同時改了簽發與驗章它照樣全綠。⚠️ **轉紅時要修相容性，不得重新產生 fixture**（重產＝拿新版驗新版，守護失效）。`AppClientContractTest` 守 App（`wilson-e-book-english`）的嚴格型別解析：回應欄位型別、claim 型別（`license_id` 為 int、`status` 為小寫 `active`、`licensable_*` 為 null 或字串）、footer 憑證鏈、content key 編碼，且授權端點請求**不帶 `Accept` header**（比照 dart:io）。App 遇到 validate 的 401 `INVALID_TOKEN`、validate 的任何 JSON 404、wordwall 的任何 JSON 401 會**清除本機授權**，這些狀態碼不可誤發。

**金鑰流**: content key 經 `License → Scope → ContentEncryptionKey`（`ContentKeyWrapper`）取得並 wrap；PASETO 由 `WilsonPasetoTokenService` 簽發/驗證。

**統一錯誤格式**: 一律 `{success, error: {code, message}}`。5xx 全部遮蔽成 generic `SERVER_ERROR`，完整 exception 記到 `api` log channel（`storage/logs/api-YYYY-MM-DD.log`），**絕不**回傳 stack trace。兩層防線：controller 的 `serverError()` helper + `bootstrap/app.php` 全域 render callback（含 `RATE_LIMITED` 429 + `Retry-After`）。

**Rate limiter**: 定義在 `AppServiceProvider::boot()`。`licensing-activate`/`licensing-validate` 用 `sha1(IP|fingerprint)` 複合鍵、limit 取自 `config/licensing.php` 的 `rate_limit.*`；`licensing-diagnostics` 同樣用 `sha1(IP|fingerprint)`、60/min（`rate_limit.diagnostics_per_minute`）。throttle 排在 controller 驗證之前，`fingerprint` 此時可能是陣列——複合鍵對非純量一律當空值，否則會「Array to string conversion」回 500 而不是 422；`api-wordwall`（`v1/wordwall-categories` 與 `v1/wordwall-categories/{id}/image` 共用此具名 limiter）用 `sha1(IP|path)`——key 帶 path 讓各端點獨立 60/min、不互相排擠（圖片端點因 path 含分類 id，每個分類圖亦各自獨立計數）。

**Middleware**: `LogApiCall`（prepend 到所有 api 請求）、`VerifyBearerToken`（PASETO 簽章驗證）、`EnsureLicenseUsable`（授權現況驗證）、`SetResponseEtag`（ETag）。

**認證與授權分兩層（刻意拆開）**: `VerifyBearerToken` 只驗簽章（authentication）；「這張授權現在還可不可用」由 `EnsureLicenseUsable` 負責（authorization），條件與 `guardLicenseState()` 同源（Cancelled/Suspended/Grace/`isExpired()`/`! isUsable()`）。**兩者必須拆開**——validate 端點只掛前者，因為授權失效時它必須仍可達，才能回覆帶原因的 410/423 讓 App 分流（cancelled 清本地金鑰、suspended 等管理者恢復）；併進 `VerifyBearerToken` 會讓 validate 先被擋成 401，App 永遠問不出原因。兩個 `v1/*` 內容端點兩者都掛，`EnsureLicenseUsable` 排在後面（它讀前者寫入的 `license_claims`）。少了這層，授權被取消/停權/到期後既發 token 仍可無限期存取內容（永久授權 token TTL 為 100 年），伺服器端唯一撤銷手段只剩輪替 signing key（會一次打掛所有客戶）。內容端點對停權授權回 423 `SUSPENDED_LICENSE`，其餘一律回 401 `INVALID_TOKEN`（真正狀態只寫 log），因為 App 對內容端點的任何 401 都會直接清除本機授權、不會先問 validate；由 `suspended license is answered with 423 so the App keeps its keys` 守護。⚠️ `EnsureLicenseUsable` 刻意只查 License 不查 LicenseUsage——席位是一次性設計，目前沒有任何路徑會讓 usage 離開 active（`over_limit => reject`、`usage_inactivity_auto_revoke_days => null`、後台與 API 皆無撤銷功能），**若日後加入席位撤銷必須一併補上**。由 `tests/Feature/Api/EnsureLicenseUsableTest.php` 守護。

**Log 遮蔽（`LogApiCall`）**: `REDACTED_KEYS` 逐鍵**遞迴**遮蔽 request 與 response body（`license_key`、`token`、`validation_token`、`wrapped_content_key`、`password`、`private_key`、`encrypted_key`、`passphrase`）。必須遞迴，因為 vendor `buildLicenseResponse()` 組出的是巢狀結構。遮蔽後補一個 `license_key_hash_prefix`（`key_hash` 前 12 字元，HMAC 截斷值不可逆）保留客服追查能力。歷史成因：先前 `$request->except(['password'])` 只排除一個本專案不存在的欄位，且 `$shouldLogResponseBody` 對所有非 GET 為 true，於是 activate/validate 的**明文授權碼**與**簽發的 PASETO** 全量落地 `storage/logs/api-*.log`——`License::$hidden`、`ContentEncryptionKey::$hidden` 等 model 層防護全部被繞過（它們只作用於序列化）。注意 `config/logging.php` 的 `days => 30` 是 Monolog 的 **30 個檔案**而非 30 天，流量稀疏時實際保存期遠超過 30 天。由 `LogApiCallTest` 的 `credential material never reaches the log` 以整份 log 原文斷言守護。 **例外：診斷上傳端點只記摘要**（`session_id`、指紋前 8 字元、事件筆數）——body 最多 500 筆事件且會因重試重複送達，事件本身也已存進 `diagnostic_events`，全量落地只會灌爆 api log。以 **path** 判斷而非由 controller 標記，被 throttle 擋下或驗證失敗（controller 沒執行到）時同樣生效；由 `diagnostics upload logs a summary instead of the whole event batch` 與 `a rejected diagnostics upload is summarised too` 守護。

## 套件整合注意事項

- 安裝或設定第三方套件前，先檢查 ServiceProvider 的所有發佈項目（config、migration、translation namespace、views）
- Migration 執行前先檢查外鍵依賴順序與 MySQL 索引名稱長度限制（64 字元）
- 不要用自訂類別覆寫來迴避翻譯或顯示問題，優先找根本原因
- `config/licensing-filament-manager.php` 的 `licensed_entities` 尚未設定
- LicenseTemplate：vendor 2.0 重新引入 Template 功能，但本專案刻意不使用。`license_templates` 表已刪除，`config/licensing.php` 中 `templates.enabled` 設為 `false`。`CustomLicensingPlugin` 已覆寫 `getWidgets()` 以避免 vendor widget 參照 Template。若需啟用 Template，須先還原 migration 並更新 config

## Audit 守則（License / LicenseScope / LicenseUsage）

`License`、`LicenseScope`、`LicenseUsage` 三個 model 透過 `owen-it/laravel-auditing` 記錄變更（commit 82dc98b）。Audit 掛在 Eloquent 的 `created`/`updated`/`deleted` 事件上，**任何不走 model 的操作都會繞過 audit 不留痕跡**。

### 禁止事項
- ❌ Filament `BulkAction` / `toolbarActions`（內部 `Builder::delete()`/`update()`，繞事件）。需要批次操作時，請寫成自訂 `Action`，內部 `foreach` 逐筆呼叫 `$record->save()` 或 `$record->delete()`
- ❌ Filament `reorderable()`（拖曳排序用 mass update 寫排序欄位，繞事件）
- ❌ `saveQuietly()`、`updateQuietly()`、`deleteQuietly()`、`Model::withoutEvents()`
- ❌ `Model::where(...)->update()` / `Model::query()->update/delete()` 等 mass update/delete
- ❌ 對 `licenses` / `license_scopes` / `license_usages` 表使用 `DB::table()`

### 多層保護機制
1. **Filament 結構守門員** — `tests/Feature/Filament/AuditTargetResourceGuardrailTest.php` 透過 reflection 自動掃描所有 audit target Resource 與 RelationManager，斷言沒有 `toolbarActions` 也沒有 reorder 欄位
2. **Filament audit 整合測試** — `tests/Feature/Filament/FilamentAuditTrailTest.php` 驗證 Filament 的 create/edit/delete 都會寫 audit 並帶 actor
3. **Runtime 保護** — `App\Models\Concerns\EnforcesAuditEvents` trait 覆寫 `saveQuietly`/`updateQuietly`/`deleteQuietly` 為拋出 `LogicException`，攔截動態派發呼叫
4. **AST 靜態分析** — `tests/Feature/Audit/AuditTargetStaticAnalysisTest.php` 用 `nikic/php-parser` 掃 `app/` 偵測 quiet API 呼叫與 mass update/delete，能解析 use alias
5. **CI 守則** — `bin/audit-bypass-scan.sh`（`composer audit-scan`）grep 偵測，可整合到 CI 或 git hook

⚠️ **上述 5 層全部只看 PHP 層，看不到 DB schema**。DB 外鍵的 `ON DELETE CASCADE`/`SET NULL` 由 DB 引擎執行，不派發 Eloquent 事件，五層一個都攔不到。已知兩條：
- `licenses.license_scope_id` → `nullOnDelete()`：刪 LicenseScope 會靜默清空旗下所有 License 的 scope 關聯，既無 audit，也讓該產品線全部授權失去 content encryption key、activate 永久回 500。**已於 `App\Models\LicenseScope::booted()` 的 `deleting` hook 擋住**（有 License 就拋 `LogicException`），Filament 兩條刪除路徑（列表 `recordActions` 與編輯頁 header）另各自 `->visible()` 藏鈕。由 `tests/Feature/Filament/LicenseScopeDeleteGuardTest.php` 守護。
- `license_usages.license_id` → `cascadeOnDelete()`：刪 License 會連帶刪除席位且不寫 `deleted` audit。**經評估可接受**——`audits` 表用 `morphs()` 無外鍵，cascade 碰不到它，席位建立當下的 `created` audit（含 fingerprint、ip、user_agent、registered_at、meta）完整保留，鑑識資料未遺失。

⚠️ **另一條繞過路徑：拿到的根本不是 audit target model**。五層都假設程式碼操作的是 `App\Models\*`，但只要某條路徑硬編碼了 vendor model class（不讀 `config('licensing.models.*')`），`implements Auditable` 就從未生效，五層一個都不會響——沒有 quiet API 呼叫、沒有 mass update，靜態掃描與 runtime 保護都看不出異常。已知一條（activate/validate 的 `findByKey`）已修並由 `LicenseModelBindingTest` 守護，詳見上方「Vendor 覆寫」的 model binding 陷阱。**接上 vendor 提供的新入口時，先確認它回傳的是哪個 class**。

新增 audit target 的關聯時，務必**同時檢查 migration 的 FK 連動行為**，需要時在 model 層補 hook。

### 加新 audit 對象 model 的步驟
1. Model 上 `implements OwenIt\Auditing\Contracts\Auditable` + `use \OwenIt\Auditing\Auditable;`
2. 加 `use App\Models\Concerns\EnforcesAuditEvents;`（runtime 保護）
3. 將表名加入 `bin/audit-bypass-scan.sh` 的 `TARGET_TABLES`
4. 將 model FQN 加入 `tests/Feature/Audit/AuditTargetStaticAnalysisTest.php` 檔案頂層的 `$auditTargetModels` 陣列（改寫為 Pest 語法後已由 class 常數改為檔案級變數）
5. 在 `AuditTargetResourceGuardrailTest::test_discovery_finds_all_known_audit_target_models` 加入新 model 到 `$expected`
6. 跑 `composer audit-scan` + audit 相關測試確認全綠

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.5. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `vendor/bin/sail composer show --direct` to list direct dependencies with versions, or `vendor/bin/sail composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `vendor/bin/sail npm run build` or ask the user to run `vendor/bin/sail npm run dev` or `vendor/bin/sail composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `vendor/bin/sail artisan route:list`). Use `vendor/bin/sail artisan list` to discover available commands and `vendor/bin/sail artisan [command] --help` to check parameters.
- Inspect routes with `vendor/bin/sail artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `vendor/bin/sail artisan config:show app.name`, `vendor/bin/sail artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `vendor/bin/sail artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `vendor/bin/sail artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.
- Activate the `deploying-to-cloud` skill whenever deploying to Laravel Cloud, configuring Cloud environments or resources, using the Cloud CLI, or troubleshooting Cloud deployments.

=== sail rules ===

# Laravel Sail

- This project runs inside Laravel Sail's Docker containers. You MUST execute all commands through Sail.
- Start services using `vendor/bin/sail up -d` and stop them with `vendor/bin/sail stop`.
- Open the application in the browser by running `vendor/bin/sail open`.
- Always prefix PHP, Artisan, Composer, and Node commands with `vendor/bin/sail`. Examples:
    - Run Artisan Commands: `vendor/bin/sail artisan migrate`
    - Install Composer packages: `vendor/bin/sail composer install`
    - Execute Node commands: `vendor/bin/sail npm run dev`
    - Execute PHP scripts: `vendor/bin/sail php [script]`
- View all available Sail commands by running `vendor/bin/sail` without arguments.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `vendor/bin/sail artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `vendor/bin/sail artisan list` and check their parameters with `vendor/bin/sail artisan [command] --help`.
- If you're creating a generic PHP class, use `vendor/bin/sail artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `vendor/bin/sail artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `vendor/bin/sail artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/sail bin pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/sail bin pint --test --format agent`, simply run `vendor/bin/sail bin pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `vendor/bin/sail artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `vendor/bin/sail artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/sail bin pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.

</laravel-boost-guidelines>
