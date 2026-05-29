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
- **排程與 CLI**: `licensing:check-expirations` 每日把過期 License 由 active→grace→expired（`routes/console.php`，受 `config/licensing.php` 的 `scheduler.check_expirations` 控制，`onOneServer`+`withoutOverlapping`）。另有 `content-key:create`/`content-key:list`、`licensing:passphrase-generate` 等 CLI。Wordwall（model + Filament resource + `v1/wordwalls` API）非 audit 對象

## API 層

對外授權/內容 API。vendor `licensing.api.enabled = false`，**所有路由手動註冊在 `routes/api.php`**：

- `POST api/licensing/v1/activate` — `Api\LicenseController::activate`，ECDH(X25519) content key wrapping + 簽發 PASETO token。Throttle `licensing-activate`
- `POST api/licensing/v1/validate` — `Api\ValidateController`，heartbeat 合併，需回帶 activate 簽發的 PASETO token（`VerifyBearerToken`）。Throttle `licensing-validate`
- `GET api/v1/wordwalls` — `Api\WordwallController`，需 bearer token + ETag 條件式請求（`SetResponseEtag`）。Throttle `api-wordwall`

**Vendor 覆寫（升級 gotcha）**: 三處**複製自 vendor 並就地插入自訂邏輯**的覆寫——`Api\LicenseController extends` vendor `BaseLicenseController`（`activate()`）、`ValidateController extends LicenseController`（`validateLicense()`）、`App\Services\WilsonPasetoTokenService extends` vendor `PasetoTokenService`（`issue()`，PASETO 簽章核心）。方法註解皆標註「對齊版本：vendor 2.1.1」。**升級 `masterix21/laravel-licensing` 時務必逐一重新對齊 parent 的最新實作**——例如 vendor 自 2.0.1 起把 signing key 建構改為「取前 32 bytes seed 經 `AsymmetricSecretKey::v4()` 重新推導 keypair」（`buildSecretKey()`），以通過 paragonie/paseto 3.5 的 v4 misuse-resistance 檢查；此修正需同步移植，否則輪替到特定金鑰時 `issue()` 會簽章失敗。此真實簽發路徑由 `tests/Feature/Api/LicensePasetoIssuanceTest.php`（真實 root/signing key、不 mock，含 activate 端點端對端解 content key）守護；其餘 API 測試以 `MocksLicenseTokenVerifier` mock 掉 verifier 故碰不到此路徑，另有 `tests/e2e_activate_verify.php` 手動 E2E 腳本。

**金鑰流**: content key 經 `License → Scope → ContentEncryptionKey`（`ContentKeyWrapper`）取得並 wrap；PASETO 由 `WilsonPasetoTokenService` 簽發/驗證。

**統一錯誤格式**: 一律 `{success, error: {code, message}}`。5xx 全部遮蔽成 generic `SERVER_ERROR`，完整 exception 記到 `api` log channel（`storage/logs/api-YYYY-MM-DD.log`），**絕不**回傳 stack trace。兩層防線：controller 的 `serverError()` helper + `bootstrap/app.php` 全域 render callback（含 `RATE_LIMITED` 429 + `Retry-After`）。

**Rate limiter**: 定義在 `AppServiceProvider::boot()`。`licensing-activate`/`licensing-validate` 用 `sha1(IP|fingerprint)` 複合鍵、limit 取自 `config/licensing.php` 的 `rate_limit.*`；`api-wordwall` 用 IP。

**Middleware**: `LogApiCall`（prepend 到所有 api 請求）、`VerifyBearerToken`（PASETO 驗證）、`SetResponseEtag`（ETag）。

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

### 加新 audit 對象 model 的步驟
1. Model 上 `implements OwenIt\Auditing\Contracts\Auditable` + `use \OwenIt\Auditing\Auditable;`
2. 加 `use App\Models\Concerns\EnforcesAuditEvents;`（runtime 保護）
3. 將表名加入 `bin/audit-bypass-scan.sh` 的 `TARGET_TABLES`
4. 將 model FQN 加入 `tests/Feature/Audit/AuditTargetStaticAnalysisTest::AUDIT_TARGET_MODELS`
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
- phpunit/phpunit (PHPUNIT) - v12
- tailwindcss (TAILWINDCSS) - v4

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

=== phpunit/core rules ===

# PHPUnit

- This application uses PHPUnit for testing. All tests must be written as PHPUnit classes. Use `vendor/bin/sail artisan make:test --phpunit {name}` to create a new test.
- If you see a test using "Pest", convert it to PHPUnit.
- Every time a test has been updated, run that singular test.
- When the tests relating to your feature are passing, ask the user if they would like to also run the entire test suite to make sure everything is still passing.
- Tests should cover all happy paths, failure paths, and edge cases.
- You must not remove any tests or test files from the tests directory without approval. These are not temporary or helper files; these are core to the application.

## Running Tests

- Run the minimal number of tests, using an appropriate filter, before finalizing.
- To run all tests: `vendor/bin/sail artisan test --compact`.
- To run all tests in a file: `vendor/bin/sail artisan test --compact tests/Feature/ExampleTest.php`.
- To filter on a particular test name: `vendor/bin/sail artisan test --compact --filter=testName` (recommended after making a change to a related file).

</laravel-boost-guidelines>
