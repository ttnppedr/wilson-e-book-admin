# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

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
- **測試**: Pest 5，**27 個測試檔全部是 Pest closure 語法**（`test('...', function () {...})`）。`tests/Pest.php` 只把 `Tests\TestCase` 綁到 `Feature`；`Unit` 沿用 Pest 預設的 `PHPUnit\Framework\TestCase`，維持 `ContentKeyWrapperTest` 不啟動 Laravel app。**新測試一律寫 Pest 語法，不要退回 PHPUnit class**——只要有任何一個 class 型測試存在，`--tia` 就會整個停用（見下）。慣用轉換：`setUp` → `beforeEach`、`tearDown` → `afterEach`、`private helper` → 檔案級 closure（需 `$this` 者用 `$closure->call($this, ...)`）、`#[DataProvider]` → `dataset()` + `->with()`。斷言一律沿用 `$this->assertX()`，不必改寫成 `expect()`
- **測試效能**: 三種模式擇一。`vendor/bin/pest --tia`（**最快，日常首選**）：無變更 0.4s、改動一個 model 約 5s（只重跑受影響者、其餘 replay）；`composer test:parallel` 2.5s；完整序列 10.2s。TIA 靠執行時記錄的相依圖判斷影響範圍，**變更偵測是語意層級**——加空行/改排版不會觸發重跑，改 `Wordwall::category()` 的外鍵則精準牽動 63 個測試。首次或相依圖過期時會自動全跑重建（約 12s）。`--profile` 顯示負載分佈平均、無單一瓶頸（最慢 10 個僅佔 29%，最慢者為 `LicensePasetoIssuanceTest` 的真實 Ed25519 簽章，刻意不 mock），故逐個優化個別測試不會有回報。**`--dirty` 實測無效**（即使測試檔確有 git 變更仍跑滿全部、也不拋 `NoDirtyTestsFound`），直接用 `--tia` 取代它
- **測試工具鏈版本凍結**: Pest 5.0.0 宣告 `conflict: phpunit/phpunit >13.2.4`，把 PHPUnit 釘死在單一 patch 版；`brianium/paratest` 7.23.1 因 require `phpunit ^13.2.5` 而被同一條約束連帶擋住。故 `composer outdated` 會持續顯示 phpunit 13.2.6、paratest 7.23.1 可升卻升不動，**此為預期狀態、非設定損壞**，解凍點是 Pest 發布跟上 PHPUnit 13.2.6 的版本。`phpunit/phpunit` 已從 `require-dev` 移除（改由 pest 以 transitive 相依管理），避免 `outdated --direct` 永遠掛著一個升不動的項目
- **寬限期為 0 天**: 授權的到期日一直是 `expires_at`（判斷點從未改變），改變的是**到期後不再給寬限**。⚠️ 光把 `config('licensing.policies.grace_days')` 設為 0 **達不到**這個效果——vendor 的 `License::isInGracePeriod()` 只看 `status === Grace`、**完全不讀 grace_days**（讀天數的是另一支 `gracePeriodExpired()`），所以 status 為 Grace 的授權在 vendor 原本的 `isExpired() && ! isInGracePeriod()` 下仍會被放行。因此實作改從狀態機下手：`App\Models\License::transitionToGrace()` 覆寫為 no-op（授權永遠進不了 Grace），`guardLicenseState()` 直接看 `isExpired()`。config 的 `grace_days => 0` 只是讓 vendor 內殘留的 grace 計算立即為真，不是把關點
- **排程與 CLI**: `licensing:check-expirations` 每日把過期 License 由 active 直接轉 expired（不經 grace；Grace 仍列在掃描條件內僅為收斂舊資料）（`routes/console.php`，受 `config/licensing.php` 的 `scheduler.check_expirations` 控制，`onOneServer`+`withoutOverlapping`）。另有 `content-key:create`/`content-key:list`、`licensing:passphrase-generate` 等 CLI。Wordwall 與 WordwallCategory（遊戲分類，name + 圖片；與 Wordwall 為 nullable 一對多，刪分類時遊戲 FK `nullOnDelete`）皆非 audit 對象（model + Filament resource + `v1/wordwalls`、`v1/wordwall-categories`、`v1/wordwall-categories/{id}/image` API），故可合法用 `reorderable('sort')`

## API 層

對外授權/內容 API。vendor `licensing.api.enabled = false`，**所有路由手動註冊在 `routes/api.php`**：

- `POST api/licensing/v1/activate` — `Api\LicenseController::activate`，ECDH(X25519) content key wrapping + 簽發 PASETO token。Throttle `licensing-activate`
- `POST api/licensing/v1/validate` — `Api\ValidateController`，heartbeat 合併，需回帶 activate 簽發的 PASETO token（`VerifyBearerToken`）。Throttle `licensing-validate`
- `GET api/v1/wordwalls` — `Api\WordwallController`，需 bearer token（`VerifyBearerToken` + `EnsureLicenseUsable`）+ ETag 條件式請求（`SetResponseEtag`）。Throttle `api-wordwall`
- `GET api/v1/wordwall-categories` — `Api\WordwallCategoryController`，**巢狀**回傳每個分類（`id, name, image_url, sort`）+ 其底下依 `sort` 排序的 wordwalls（`id, sort, resource_url`），供 App 遊戲頁兩層導覽（分類 → 該分類的遊戲）。需 bearer token + ETag（`SetResponseEtag`）。Throttle `api-wordwall`。兩層 query 皆加 `orderBy('id')` tie-break 確保 ETag 穩定。圖片 `image_url` **不直接給 S3 網址**，而由 `WordwallCategory::image_url` accessor 產生指向下方圖片轉址端點的**穩定 URL**（帶 `v=md5(image_path)` 片段，換圖時即變動以汰換 client 快取、同時不外洩原始儲存路徑）——刻意不放 presigned URL，因其簽章/時間戳每次不同會讓內容型 ETag 每次失效
- `GET api/v1/wordwall-categories/{id}/image` — `Api\WordwallCategoryImageController`，S3 物件**全程 private**：請求當下以 `Storage::temporaryUrl()` 簽發短效（15 分）presigned 網址並 **302 轉址**，故 bucket 可關閉公開存取（不可列舉/盜連），授權從 bucket 層上移到應用層。需 bearer token（`VerifyBearerToken`，**App 載圖須帶 `Authorization` header**）。**不掛** `SetResponseEtag`（回應為轉址、非可快取 body）。Throttle `api-wordwall`。無圖回統一格式 `IMAGE_NOT_FOUND` 404。後台 Filament 的 `FileUpload`/`ImageColumn` 亦設 `visibility('private')`，預覽由 Filament 對 private disk 自動簽發 `temporaryUrl`（過期對齊整點以利快取）。⚠️ **`ImageColumn` 必須加 `->checkFileExistence(false)`**：它預設 `shouldCheckFileExistence = true`，會在產生預覽網址前先 `$storage->exists()`——對 s3 disk 是一次**真實 HeadObject 網路往返，且逐列序列化**。`WordwallCategoryResource` 的表格 `->paginated(false)`（因 `reorderable('sort')` 與分頁衝突）又有 `->searchable()`（每個按鍵一次 Livewire 往返、整表重繪），成本因此是「分類數 × S3 RTT × 每次互動」。本機 MinIO 同主機約 1.3ms/列察覺不到，正式站對真實 S3 是 15–40ms/列——120 筆本機 264ms、正式站數秒。`temporaryUrl()` 本身是本地 HMAC 簽章（0.3ms）不是瓶頸。由 `ManageWordwallCategoriesTest` 的 `image column does not check file existence per row` 守護

**Vendor 覆寫（升級 gotcha）**: 三處**複製自 vendor 並就地插入自訂邏輯**的覆寫——`Api\LicenseController extends` vendor `BaseLicenseController`（`activate()`）、`ValidateController extends LicenseController`（`validateLicense()`）、`App\Services\WilsonPasetoTokenService extends` vendor `PasetoTokenService`（`issue()`，PASETO 簽章核心；與 parent 有**兩處**差異，皆已就地標註「新增」——`$expiration` 以 `expires_at` 夾住上界、extra_claims 合併）。方法註解皆標註「對齊版本：vendor 2.2.1」。**升級 `masterix21/laravel-licensing` 時務必逐一重新對齊 parent 的最新實作**——例如 vendor 自 2.0.1 起把 signing key 建構改為「取前 32 bytes seed 經 `AsymmetricSecretKey::v4()` 重新推導 keypair」（`buildSecretKey()`），以通過 paragonie/paseto 3.5 的 v4 misuse-resistance 檢查；此修正需同步移植，否則輪替到特定金鑰時 `issue()` 會簽章失敗。此真實簽發路徑由 `tests/Feature/Api/LicensePasetoIssuanceTest.php`（真實 root/signing key、不 mock，含 activate 端點端對端解 content key）守護；其餘 API 測試以 `MocksLicenseTokenVerifier` mock 掉 verifier 故碰不到此路徑，另有 `tests/e2e_activate_verify.php` 手動 E2E 腳本。

**金鑰流**: content key 經 `License → Scope → ContentEncryptionKey`（`ContentKeyWrapper`）取得並 wrap；PASETO 由 `WilsonPasetoTokenService` 簽發/驗證。

**統一錯誤格式**: 一律 `{success, error: {code, message}}`。5xx 全部遮蔽成 generic `SERVER_ERROR`，完整 exception 記到 `api` log channel（`storage/logs/api-YYYY-MM-DD.log`），**絕不**回傳 stack trace。兩層防線：controller 的 `serverError()` helper + `bootstrap/app.php` 全域 render callback（含 `RATE_LIMITED` 429 + `Retry-After`）。

**Rate limiter**: 定義在 `AppServiceProvider::boot()`。`licensing-activate`/`licensing-validate` 用 `sha1(IP|fingerprint)` 複合鍵、limit 取自 `config/licensing.php` 的 `rate_limit.*`；`api-wordwall`（`v1/wordwalls`、`v1/wordwall-categories` 與 `v1/wordwall-categories/{id}/image` 共用此具名 limiter）用 `sha1(IP|path)`——key 帶 path 讓各端點獨立 60/min、不互相排擠（圖片端點因 path 含分類 id，每個分類圖亦各自獨立計數）。

**Middleware**: `LogApiCall`（prepend 到所有 api 請求）、`VerifyBearerToken`（PASETO 簽章驗證）、`EnsureLicenseUsable`（授權現況驗證）、`SetResponseEtag`（ETag）。

**認證與授權分兩層（刻意拆開）**: `VerifyBearerToken` 只驗簽章（authentication）；「這張授權現在還可不可用」由 `EnsureLicenseUsable` 負責（authorization），條件與 `guardLicenseState()` 同源（Cancelled/Suspended/Grace/`isExpired()`/`! isUsable()`）。**兩者必須拆開**——validate 端點只掛前者，因為授權失效時它必須仍可達，才能回覆帶原因的 410/423 讓 App 分流（cancelled 清本地金鑰、suspended 等管理者恢復）；併進 `VerifyBearerToken` 會讓 validate 先被擋成 401，App 永遠問不出原因。三個 `v1/*` 內容端點兩者都掛，`EnsureLicenseUsable` 排在後面（它讀前者寫入的 `license_claims`）。少了這層，授權被取消/停權/到期後既發 token 仍可無限期存取內容（永久授權 token TTL 為 100 年），伺服器端唯一撤銷手段只剩輪替 signing key（會一次打掛所有客戶）。內容端點對外一律回 401 `INVALID_TOKEN` 不分流，真正狀態只寫 log。⚠️ `EnsureLicenseUsable` 刻意只查 License 不查 LicenseUsage——席位是一次性設計，目前沒有任何路徑會讓 usage 離開 active（`over_limit => reject`、`usage_inactivity_auto_revoke_days => null`、後台與 API 皆無撤銷功能），**若日後加入席位撤銷必須一併補上**。由 `tests/Feature/Api/EnsureLicenseUsableTest.php` 守護。

**Log 遮蔽（`LogApiCall`）**: `REDACTED_KEYS` 逐鍵**遞迴**遮蔽 request 與 response body（`license_key`、`token`、`validation_token`、`wrapped_content_key`、`password`、`private_key`、`encrypted_key`、`passphrase`）。必須遞迴，因為 vendor `buildLicenseResponse()` 組出的是巢狀結構。遮蔽後補一個 `license_key_hash_prefix`（`key_hash` 前 12 字元，HMAC 截斷值不可逆）保留客服追查能力。歷史成因：先前 `$request->except(['password'])` 只排除一個本專案不存在的欄位，且 `$shouldLogResponseBody` 對所有非 GET 為 true，於是 activate/validate 的**明文授權碼**與**簽發的 PASETO** 全量落地 `storage/logs/api-*.log`——`License::$hidden`、`ContentEncryptionKey::$hidden` 等 model 層防護全部被繞過（它們只作用於序列化）。注意 `config/logging.php` 的 `days => 30` 是 Monolog 的 **30 個檔案**而非 30 天，流量稀疏時實際保存期遠超過 30 天。由 `LogApiCallTest` 的 `credential material never reaches the log` 以整份 log 原文斷言守護。

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

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.5
- filament/filament (FILAMENT) - v5
- laravel/framework (LARAVEL) - v13
- laravel/nightwatch (NIGHTWATCH) - v1
- laravel/prompts (PROMPTS) - v0
- livewire/livewire (LIVEWIRE) - v4
- laravel/boost (BOOST) - v2
- laravel/mcp (MCP) - v0
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- pestphp/pest (PEST) - v5
- phpunit/phpunit (PHPUNIT) - v13
- tailwindcss (TAILWINDCSS) - v4

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

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `vendor/bin/sail npm run build`, `vendor/bin/sail npm run dev`, or `vendor/bin/sail composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

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

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `vendor/bin/sail artisan test --compact` with a specific filename or filter.

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

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `vendor/bin/sail npm run build` or ask the user to run `vendor/bin/sail npm run dev` or `vendor/bin/sail composer run dev`.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/sail bin pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/sail bin pint --test --format agent`, simply run `vendor/bin/sail bin pint --format agent` to fix any formatting issues.

=== pest/core rules ===

## Pest

- This project uses Pest for testing. Create tests: `vendor/bin/sail artisan make:test --pest {name}`.
- The `{name}` argument should not include the test suite directory. Use `vendor/bin/sail artisan make:test --pest SomeFeatureTest` instead of `vendor/bin/sail artisan make:test --pest Feature/SomeFeatureTest`.
- Run tests: `vendor/bin/sail artisan test --compact` or filter: `vendor/bin/sail artisan test --compact --filter=testName`.
- Do NOT delete tests without approval.

=== filament/filament rules ===

## Filament

- Filament is a Laravel UI framework built on Livewire, Alpine.js, and Tailwind CSS. UIs are defined in PHP via fluent, chainable components. Follow existing conventions in this app.
- Use the `search-docs` tool for official documentation on Artisan commands, code examples, testing, relationships, and idiomatic practices. If `search-docs` is unavailable, refer to https://filamentphp.com/docs.

### Artisan

- Always use Filament-specific Artisan commands to create files. Find available commands with the `list-artisan-commands` tool, or run `php artisan --help`.
- Inspect required options before running, and always pass `--no-interaction`.

### Patterns

Always use static `make()` methods to initialize components. Most configuration methods accept a `Closure` for dynamic values.

Use `Get $get` to read other form field values for conditional logic:

<code-snippet name="Conditional form field visibility" lang="php">
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;

Select::make('type')
    ->options(CompanyType::class)
    ->required()
    ->live(),

TextInput::make('company_name')
    ->required()
    ->visible(fn (Get $get): bool => $get('type') === 'business'),

</code-snippet>

Use `Set $set` inside `->afterStateUpdated()` on a `->live()` field to mutate another field reactively. Prefer `->live(onBlur: true)` on text inputs to avoid per-keystroke updates:

<code-snippet name="Reactive field update" lang="php">
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Str;

TextInput::make('title')
    ->required()
    ->live(onBlur: true)
    ->afterStateUpdated(fn (Set $set, ?string $state) => $set(
        'slug',
        Str::slug($state ?? ''),
    )),

TextInput::make('slug')
    ->required(),

</code-snippet>

Compose layout by nesting `Section` and `Grid`. Children need explicit `->columnSpan()` or `->columnSpanFull()`:

<code-snippet name="Section and Grid layout" lang="php">
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;

Section::make('Details')
    ->schema([
        Grid::make(2)->schema([
            TextInput::make('first_name')
                ->columnSpan(1),
            TextInput::make('last_name')
                ->columnSpan(1),
            TextInput::make('bio')
                ->columnSpanFull(),
        ]),
    ]),

</code-snippet>

Use `Repeater` for inline `HasMany` management. `->relationship()` with no args binds to the relationship matching the field name:

<code-snippet name="Repeater for HasMany" lang="php">
use Filament\Forms\Components\Repeater;

Repeater::make('qualifications')
    ->relationship()
    ->schema([
        TextInput::make('institution')
            ->required(),
        TextInput::make('qualification')
            ->required(),
    ])
    ->columns(2),

</code-snippet>

Use `state()` with a `Closure` to compute derived column values:

<code-snippet name="Computed table column value" lang="php">
use Filament\Tables\Columns\TextColumn;

TextColumn::make('full_name')
    ->state(fn (User $record): string => "{$record->first_name} {$record->last_name}"),

</code-snippet>

Use `SelectFilter` for enum or relationship filters, and `Filter` with a `->query()` closure for custom logic:

<code-snippet name="Table filters" lang="php">
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

SelectFilter::make('status')
    ->options(UserStatus::class),

SelectFilter::make('author')
    ->relationship('author', 'name'),

Filter::make('verified')
    ->query(fn (Builder $query) => $query->whereNotNull('email_verified_at')),

</code-snippet>

Actions are buttons that encapsulate optional modal forms and behavior:

<code-snippet name="Action with modal form" lang="php">
use Filament\Actions\Action;

Action::make('updateEmail')
    ->schema([
        TextInput::make('email')
            ->email()
            ->required(),
    ])
    ->action(fn (array $data, User $record) => $record->update($data)),

</code-snippet>

### Testing

Testing setup (requires `pestphp/pest-plugin-livewire` in `composer.json`):

- Always call `$this->actingAs(User::factory()->create())` before testing panel functionality.
- For edit pages, pass `['record' => $user->id]`, use `->call('save')` (not `->call('create')`), and do not assert `->assertRedirect()` (edit pages do not redirect after save).

<code-snippet name="Table test" lang="php">
use function Pest\Livewire\livewire;

livewire(ListUsers::class)
    ->assertCanSeeTableRecords($users)
    ->searchTable($users->first()->name)
    ->assertCanSeeTableRecords($users->take(1))
    ->assertCanNotSeeTableRecords($users->skip(1));

</code-snippet>

<code-snippet name="Create resource test" lang="php">
use function Pest\Laravel\assertDatabaseHas;

livewire(CreateUser::class)
    ->fillForm([
        'name' => 'Test',
        'email' => 'test@example.com',
    ])
    ->call('create')
    ->assertNotified()
    ->assertHasNoFormErrors()
    ->assertRedirect();

assertDatabaseHas(User::class, [
    'name' => 'Test',
    'email' => 'test@example.com',
]);

</code-snippet>

<code-snippet name="Edit resource test" lang="php">
livewire(EditUser::class, ['record' => $user->id])
    ->fillForm(['name' => 'Updated'])
    ->call('save')
    ->assertNotified()
    ->assertHasNoFormErrors();

assertDatabaseHas(User::class, [
    'id' => $user->id,
    'name' => 'Updated',
]);

</code-snippet>

<code-snippet name="Testing validation" lang="php">
livewire(CreateUser::class)
    ->fillForm([
        'name' => null,
        'email' => 'invalid-email',
    ])
    ->call('create')
    ->assertHasFormErrors([
        'name' => 'required',
        'email' => 'email',
    ])
    ->assertNotNotified();

</code-snippet>

Use `->callAction(DeleteAction::class)` for page actions, or `->callAction(TestAction::make('name')->table($record))` for table actions:

<code-snippet name="Calling actions" lang="php">
use Filament\Actions\Testing\TestAction;

livewire(ListUsers::class)
    ->callAction(TestAction::make('promote')->table($user), [
        'role' => 'admin',
    ])
    ->assertNotified();

</code-snippet>

### Correct Namespaces

- Form fields (`TextInput`, `Select`, `Repeater`, etc.): `Filament\Forms\Components\`
- Infolist entries (`TextEntry`, `IconEntry`, etc.): `Filament\Infolists\Components\`
- Layout components (`Grid`, `Section`, `Fieldset`, `Tabs`, `Wizard`, etc.): `Filament\Schemas\Components\`
- Schema utilities (`Get`, `Set`, etc.): `Filament\Schemas\Components\Utilities\`
- Table columns (`TextColumn`, `IconColumn`, etc.): `Filament\Tables\Columns\`
- Table filters (`SelectFilter`, `Filter`, etc.): `Filament\Tables\Filters\`
- Actions (`DeleteAction`, `CreateAction`, etc.): `Filament\Actions\`. Never use `Filament\Tables\Actions\`, `Filament\Forms\Actions\`, or any other sub-namespace for actions.
- Icons: `Filament\Support\Icons\Heroicon` enum (e.g., `Heroicon::PencilSquare`)

### Common Mistakes

- **Never assume public file visibility.** File visibility is `private` by default. Always use `->visibility('public')` when public access is needed.
- **Never assume full-width layout.** `Grid`, `Section`, `Fieldset`, and `Repeater` do not span all columns by default.
- **Use `Select::make('author_id')->relationship('author', 'name')` for BelongsTo fields.** `BelongsToSelect` does not exist in v4.
- **`Repeater` uses `->schema()`, not `->fields()`.**
- **Never add `->dehydrated(false)` to fields that need to be saved.** It strips the value from form state before `->action()` or the save handler runs. Only use it for helper/UI-only fields.
- **Use correct property types when overriding `Page`, `Resource`, and `Widget` properties.** These properties have union types or changed modifiers that must be preserved:
  - `$navigationIcon`: `protected static string | BackedEnum | null` (not `?string`)
  - `$navigationGroup`: `protected static string | UnitEnum | null` (not `?string`)
  - `$view`: `protected string` (not `protected static string`) on `Page` and `Widget` classes

=== filament/blueprint rules ===

## Filament Blueprint

You are writing Filament v5 implementation plans. Plans must be specific enough
that an implementing agent can write code without making decisions.

**Start here**: Read
`/vendor/filament/blueprint/resources/markdown/planning/overview.md` for plan format,
required sections, and what to clarify with the user before planning.

</laravel-boost-guidelines>
