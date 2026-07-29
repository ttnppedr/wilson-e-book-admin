# 部署指令清單

本文件整理本專案部署時，為了讓**管理者可登入 Filament 後台**、以及讓**客戶端 App 可呼叫授權／素材 API**，所需執行的設定與 artisan 指令。

## 先選部署路線

| 目標環境 | 看哪裡 | 說明 |
| --- | --- | --- |
| **正式站：Laravel Cloud** | **[第 9 節](#9-laravel-cloud-部署正式站)** | 本專案正式站的部署方式。第 1–8 節的自管主機流程**不適用**——Cloud 沒有可持久化的 `.env`、沒有 OS cron、檔案系統為 ephemeral。請直接跳到第 9 節 |
| 自管主機 / Docker / Sail | 第 1–8 節 | 自行架設的伺服器，或本機重建環境 |

第 1–8 節同時是**觀念說明**（金鑰在做什麼、排程為何必要、CEK 怎麼綁），第 9 節會回頭引用而不重複，因此走 Cloud 路線時仍建議先讀第 4、5、7 節的說明段落。

第 1–8 節的指令都透過 Sail 執行；若部署環境未使用 Sail，將前綴替換為 `php` 即可。

---

## 1. 前置準備（非 artisan）

```bash
# 1. 取得程式碼
git clone <repo> && cd wilson-e-book-admin

# 2. 安裝 PHP 依賴
vendor/bin/sail composer install --no-dev --optimize-autoloader

# 3. 建立 .env
cp .env.example .env
```

需在 `.env` 中設定：

| 變數 | 必要性 | 說明 |
| --- | --- | --- |
| `APP_ENV=production`、`APP_DEBUG=false` | 必要 | `APP_DEBUG=true` 會讓 API 錯誤回傳 stack trace，違反統一錯誤格式的遮蔽設計 |
| `APP_URL` | 必要 | 對外網址，Filament 與 `Storage::url()` 產絕對網址時使用 |
| `APP_TIMEZONE=Asia/Taipei` | 必要 | 排程執行時刻與後台顯示時間都依賴它 |
| `DB_*` | 必要 | 資料庫連線 |
| `FILESYSTEM_DISK=s3` + `AWS_*` | 必要 | Wordwall 分類圖片走 S3 相容儲存與 presigned 直傳。**未設定會導致圖片上傳與 `v1/wordwall-categories/{id}/image` 端點失效** |
| `SESSION_DRIVER=database` | 必要 | 不可用 `cookie`——Filament 的 session 內容會撐爆 4KB 的 `Set-Cookie` 上限 |
| `CACHE_STORE=database` | 必要 | 排程的 `onOneServer()` 需要支援 atomic lock 的 driver；`file` 不支援 |
| `QUEUE_CONNECTION` | — | 本專案目前**沒有任何 queued job**，此值不影響運作 |
| `LICENSING_KEY_SALT` | 可選 | 授權碼雜湊鹽值，未設定會使用 `APP_KEY` |
| `LICENSING_TOKEN_ISSUER` | 可選 | PASETO token issuer，未設定會使用預設值 `wilson-ebook-admin`。須與 App 端 `LicenseConstants.expectedIssuer` 一致 |

> `LICENSING_KEY_PASSPHRASE` 不在此手動設定，由第 2 節的 `licensing-passphrase:generate` 指令產生。

```bash
# 4. 啟動 Sail 容器
vendor/bin/sail up -d
```

---

## 2. 應用程式初始化

| 指令 | 用途 |
| --- | --- |
| `vendor/bin/sail artisan key:generate` | 產生 `APP_KEY`，用於 session、cookie、`Crypt` 加密與（未設定 `LICENSING_KEY_SALT` 時）授權碼雜湊鹽值。**務必在 migrate 之前執行** |
| `vendor/bin/sail artisan licensing-passphrase:generate` | 隨機產生 `LICENSING_KEY_PASSPHRASE`（base64 編碼的 32 bytes）並寫入 `.env`，作為加密 root 私鑰的 passphrase。**產生後立即備份**，遺失後既有 root 私鑰將永久無法解密。已存在值時需加 `--force` 才會覆寫；若只想查看而不寫入可加 `--show` |
| `vendor/bin/sail artisan migrate --force` | 建立所有資料表（`users`、`licenses`、`license_scopes`、`license_usages`、`content_encryption_keys`、`licensing_keys`、`audits`、`wordwalls`、`wordwall_categories` 等）。`--force` 在 production 環境必要（否則會跳互動確認提示） |

---

## 3. 建立後台登入帳號

```bash
vendor/bin/sail artisan make:filament-user
```

互動式建立 Filament 管理員帳號（Name / Email / Password），即可登入 `/admin`。

非互動替代指令：

```bash
vendor/bin/sail artisan tinker --execute 'App\Models\User::create(["name"=>"Admin","email"=>"admin@example.com","password"=>bcrypt("secret")]);'
```

---

## 4. 授權簽章金鑰（client API 必要）

`POST /api/licensing/v1/activate`、`POST /api/licensing/v1/validate` 都需要 Ed25519 簽章金鑰才能簽發 PASETO token，未建立時 client 完全無法取得可用 bearer token。

| 指令 | 用途 |
| --- | --- |
| `vendor/bin/sail artisan licensing:keys:make-root` | 產生根金鑰（Root Key）。會以 `LICENSING_KEY_PASSPHRASE` 加密私鑰後寫入 `licensing_keys.private_key_encrypted`。**每個環境只需執行一次**，重跑會產生新 root 並使 App 內嵌公鑰失配 |
| `vendor/bin/sail artisan licensing:keys:issue-signing --days=3650` | 由根金鑰簽發一把有效期 10 年的 signing key，activate / validate 會挑選目前 active 的 signing key 來簽 PASETO。套件硬編碼預設為 30 天，這裡明確指定以免頻繁重簽 |
| `vendor/bin/sail artisan licensing:keys:list`（選） | 驗證用：列出目前所有金鑰與狀態，確認 root 與 signing key 均 active |

> **金鑰架構、把 root 公鑰交給 App 內嵌、輪替與備份**：見
> [`docs/licensing-keys.md`](licensing-keys.md)。
> **Laravel Cloud 的完整部署流程**：見 [第 9 節](#9-laravel-cloud-部署正式站)。
> ⚠️ Laravel Cloud 等臨時檔案系統**必須**用 `database` keystore（本專案
> `config/licensing.php` 已預設），否則 redeploy 會清掉 root 私鑰。

---

## 5. 素材加密金鑰（CEK）

每個產品／版本（LicenseScope）都需要掛一把 Content Encryption Key，activate API 回傳時會以 ECDH 把 CEK 包給 client 解密素材。

```bash
# 為每個版本建立對應 CEK，例如：
vendor/bin/sail artisan content-key:create english-v1

# 列出所有 CEK（含明文金鑰，提供給 App 開發端加密素材）
vendor/bin/sail artisan content-key:list
```

建立後到 Filament 後台 **授權管理 → License Scope**，把 CEK 指派到對應 Scope（`content_encryption_key_id`）。**Scope 未綁 CEK，client 呼叫 activate 會失敗。**

---

## 6. Production 快取優化

| 指令 | 用途 |
| --- | --- |
| `vendor/bin/sail artisan config:cache` | 快取 config，減少每次請求的檔案讀取 |
| `vendor/bin/sail artisan route:cache` | 快取路由定義 |
| `vendor/bin/sail artisan view:cache` | 預編譯 Blade view |
| `vendor/bin/sail artisan filament:optimize` | 快取 Filament component registry 與 Blade Icons |

程式碼有異動重新部署時，先 `optimize:clear` 再重跑以上指令。

---

## 7. 排程任務（狀態正確性必要）

License 的 `status` 欄位**不會自動**隨 `expires_at` 轉換，必須依賴 `licensing:check-expirations` 排程每日執行，否則：

- Filament Dashboard 的「Active Licenses」統計會把已過期但未轉換的授權算進來
- `LicenseStatsOverview`、`ExpiringLicenses` widget、`LicenseScopeResource` 的計數欄位會浮報
- API 驗證端不受影響（已使用 `isExpired()` 做 lazy 判斷）

### 排程內容

| 命令 | 執行時機 | 作用 |
| --- | --- | --- |
| `licensing:check-expirations` | 每日 02:00（`config('app.timezone')`，Asia/Taipei） | `Active` 且 `expires_at < now()` → `Expired` 並觸發 `LicenseExpired` event。本專案**無寬限期**，不經 `Grace`（掃描條件仍含 `Grace` 僅為收斂舊資料） |

註冊位置：`routes/console.php`；啟用開關：`config('licensing.scheduler.check_expirations.enabled')`；執行時刻：`config('licensing.scheduler.check_expirations.time')`。

每次轉換會自動寫入 `audits` 表，`user_id` 為 `null`（代表系統觸發）。以 `WHERE user_id IS NULL` 可篩出所有系統自動變更。

### 部署時必須新增排程觸發器

`routes/console.php` 的 `Schedule::command(...)` **只是登記一張時刻表，本身不會執行**。真正跑任務的是 `php artisan schedule:run`——它跑一次就比對時刻表、挑出「此刻到期」的任務執行。因此必須有東西**每分鐘敲一次** `schedule:run`，這個角色就是「排程觸發器」。

沒設觸發器時**完全靜默**：沒有錯誤、沒有日誌，只有上面列的統計數字慢慢失真。

| 部署環境 | 觸發器 |
| --- | --- |
| **Laravel Cloud（正式站）** | 平台內建的 Scheduler 開關 → **見 [第 9.6 節](#96-排程取代第-7-節的-os-cron)**，以下 OS cron 方案皆不適用 |
| Linux / macOS 自管主機 | cron |
| Windows 自管主機 | 工作排程器或 WSL2 cron |
| 純容器化 | 常駐 `schedule:work` |

#### Linux / macOS（cron）

主機直接執行 artisan：

```bash
# crontab -e
* * * * * cd /path/to/wilson-e-book-admin && php artisan schedule:run >> /dev/null 2>&1
```

Production 用 Docker / Sail 部署時，從 host cron 進入容器執行：

```bash
* * * * * docker compose -f /path/to/docker-compose.yml exec -T laravel.test php artisan schedule:run >> /dev/null 2>&1
```

> `exec -T` 的 `-T` flag 用於 disable pseudo-TTY，cron 沒有 TTY，缺少此 flag 會**靜默失敗**。這是最常踩的雷。

#### Windows

Windows 沒有原生 cron，依部署方式選擇以下方案 A 或 B，或使用下一段的跨平台方案 C。

**方案 A：Docker Desktop + Windows 工作排程器**

Windows server 上跑 Docker Desktop（WSL2 backend）時的主流做法 — 由工作排程器每分鐘呼叫 `docker compose exec` 進入 Linux 容器執行 `schedule:run`。

以系統管理員開啟 PowerShell：

```powershell
# 建立每分鐘觸發一次的工作排程
schtasks /Create `
  /SC MINUTE /MO 1 `
  /TN "LaravelSchedule_WilsonAdmin" `
  /TR "docker compose -f C:\path\to\wilson-e-book-admin\docker-compose.yml exec -T laravel.test php artisan schedule:run" `
  /RU SYSTEM /F

# 驗證：列出已建立的排程
schtasks /Query /TN "LaravelSchedule_WilsonAdmin"

# 手動觸發一次測試
schtasks /Run /TN "LaravelSchedule_WilsonAdmin"

# 移除（需要時）
schtasks /Delete /TN "LaravelSchedule_WilsonAdmin" /F
```

> `/RU SYSTEM` 以系統帳號執行，免輸入密碼；但 Docker Desktop 若綁定使用者帳號才能連線，改用 `/RU "<user>" /RP "<password>"`。另外：Docker Desktop 須設為開機自動啟動，否則登入前排程會失敗。

**方案 B：WSL2 內部用 cron**

若 production 完全跑在 WSL2（Ubuntu 等發行版），可直接走 Linux cron 流程：

```bash
# 於 WSL2 的 Ubuntu shell 內
sudo service cron start
crontab -e
# 加入：
* * * * * cd /home/<user>/wilson-e-book-admin && php artisan schedule:run >> /dev/null 2>&1
```

> WSL2 的 cron 服務預設**不會隨 Windows 開機自動啟動**。擇一處理：
> - 在 `/etc/wsl.conf` 加入 `[boot]` 段落與 `systemd=true`，重啟 WSL 後讓 systemd 接管 cron
> - 或用 Windows 工作排程器建立「登入時觸發」的 task：`wsl -u root service cron start`

#### 方案 C：常駐 `schedule:work`（跨平台通用）

不想處理 OS 層 cron / 工作排程器時，可改用 `php artisan schedule:work` — 它本身就是輪詢執行到期排程的常駐程序，不依賴外部觸發器。用以下方式讓它常駐：

| 環境 | 常駐方式 |
| --- | --- |
| Linux / macOS host | `supervisor` 或 `systemd` service |
| Windows host（無 WSL） | 用 [NSSM](https://nssm.cc) 把指令包成 Windows 服務（開機自啟、失敗重啟） |
| Docker 容器內 | 於 `docker-compose.yml` 加一個專跑 `schedule:work` 的 service，或在現有容器用 supervisor 管理 |
| WSL2 內 | `systemd` service（需 `/etc/wsl.conf` 啟用 systemd） |

適合純容器化部署或不想維護 OS 層排程器的情境。

### 驗證

```bash
# 確認排程已註冊
vendor/bin/sail artisan schedule:list

# 手動觸發一次（不影響下一次排程時刻）
vendor/bin/sail artisan licensing:check-expirations
```

預期輸出：`轉入 expired: N`。

> `onOneServer()` 需要 cache driver 支援 atomic locks。本專案預設 `CACHE_STORE=database` 已支援，若改用 `file` driver 需移除此設定或改用 redis/memcached。

---

## 8. 進階運維指令（非首次部署）

以下指令在首次部署不需要，日常運維時視情況使用：

- `licensing:keys:rotate` — 輪替 signing key（`--reason=routine|compromised`）
- `licensing:keys:revoke {kid}` — 撤銷指定金鑰
- `licensing:keys:export` — 匯出公鑰供外部驗簽
- `licensing:offline:issue` — 簽發離線 token 給斷網環境的 client

---

## 9. Laravel Cloud 部署（正式站）

本節取代第 1–8 節的操作步驟。金鑰架構的完整說明另見
[`docs/licensing-keys.md`](licensing-keys.md)。

### 9.1 三個平台差異（決定了以下所有步驟）

| 差異 | 影響 |
| --- | --- |
| **沒有可持久化的 `.env`** | 所有設定改走 Cloud 的環境變數面板。`licensing-passphrase:generate`／`key:generate` 的**寫檔模式不可用**，必須用 `--show` 產生後貼進面板 |
| **檔案系統是 ephemeral，且每個 replica 各自獨立** | `storage/` 不跨部署、不跨 replica 保留 → licensing keystore 必須用 `database` driver（已預設）；寫進 `storage/logs/` 的日誌會消失（見 9.7）；`storage:link` 無效 |
| **沒有 OS cron** | 排程改用 Cloud 的 Scheduler 開關（見 9.6） |

### 9.2 基礎設施（infrastructure canvas）

在環境的 infrastructure canvas 建立以下資源，建立後都需要**重新部署**才生效：

| 資源 | 設定 | 備註 |
| --- | --- | --- |
| **App compute cluster** | PHP 8.5（新環境預設）、開啟 Scheduler | 專案 `composer.json` 要求 PHP 8.5。所需擴充套件 `gmp`、`sodium`、`pdo_pgsql` 皆在 Cloud 支援清單內 |
| **Serverless Postgres** | 依用量選 compute units | 正式站用 Postgres。連線變數由 Cloud 自動注入 |
| **Object storage（Laravel Object Storage / R2）** | 檔案可見性選 **Private** | 存 Wordwall 分類圖片。本專案的設計就是 bucket 全程 private、由 API 端簽發 15 分鐘 presigned URL 並 302 轉址 |
| Worker cluster | **不需要** | 本專案沒有任何 queued job（`app/`、`routes/` 內無 `dispatch()`／`ShouldQueue`）。日後若新增 queued 工作才需要開 |
| KV Store | 不需要 | `CACHE_STORE=database` 已足夠，且 database driver 支援排程 `onOneServer()` 需要的 atomic lock |

> ⚠️ **R2 不支援 per-object ACL**。官方文件明示不可在 Flysystem 設定中使用
> `visibility: 'public'`，否則 R2 會回 `NotImplemented`。本專案
> `WordwallCategoryResource` 的 `FileUpload`／`ImageColumn` 使用
> `->visibility('private')`（`app/Filament/Resources/WordwallCategories/WordwallCategoryResource.php:48,62`）——
> 官方僅明文警告 `public`，`private` 是否同樣觸發 ACL header 需在 Cloud 上**實測驗證**，列為 9.8 驗收項目。

### 9.3 環境變數

Cloud 會**自動注入**資源憑證，不要手動覆蓋這些：

- `DB_HOST`、`DB_PORT`、`DB_DATABASE`、`DB_USERNAME`、`DB_PASSWORD`（附加 Postgres 時）
- `FILESYSTEM_DISK`、`AWS_ACCESS_KEY_ID`、`AWS_SECRET_ACCESS_KEY`、`AWS_BUCKET`、`AWS_ENDPOINT`、`AWS_DEFAULT_REGION`（附加 bucket 時）
- `NIGHTWATCH_TOKEN`、`LOG_CHANNEL`、`LOG_STACK`（啟用 Nightwatch 整合時）

需**手動設定**的自訂變數：

| 變數 | 值 | 說明 |
| --- | --- | --- |
| `APP_NAME` | 專案名稱 | |
| `APP_ENV` | `production` | |
| `APP_DEBUG` | `false` | 設 `true` 會讓 API 錯誤回傳 stack trace，破壞統一錯誤格式的遮蔽設計 |
| `APP_KEY` | 本機 `php artisan key:generate --show` 產生後貼入 | **設定後永不變動**。變動會使既有授權碼雜湊全部失效（未設 `LICENSING_KEY_SALT` 時它就是鹽值），且既有 session／`Crypt` 加密資料全部解不開 |
| `APP_TIMEZONE` | `Asia/Taipei` | 排程 `dailyAt('02:00')` 的時區依據 |
| `APP_LOCALE` | `zh_TW` | |
| `APP_FALLBACK_LOCALE` | `en` | |
| `SESSION_DRIVER` | `database` | **不可用 `cookie`**——Filament 的 session 內容會超過 4KB `Set-Cookie` 上限 |
| `CACHE_STORE` | `database` | 排程 `onOneServer()` 需要 atomic lock 支援 |
| `LICENSING_KEY_PASSPHRASE` | 本機 `php artisan licensing-passphrase:generate --show` 產生後貼入 | **務必另存密碼管理器**。遺失 = root 私鑰永久無法解密 |
| `LICENSING_TOKEN_ISSUER` | `wilson-ebook-admin` | 須與 App 端 `LicenseConstants.expectedIssuer` 一致 |
| `LICENSING_KEY_SALT` | （可選） | 若要與 `APP_KEY` 解耦則顯式設定；設了就別再變 |

> **不要**把本機 `.env` 的 `AWS_ENDPOINT`／`AWS_URL`（MinIO 的
> `minio.wilson-e-book-admin.local:9000`）帶上 Cloud——會覆蓋自動注入值並使儲存完全失效。
> 本專案 bucket 為 private、對外圖片一律走 API 轉址端點，因此**不需要**設 `AWS_URL`。

環境變數變更後需要**重新部署**才生效。

### 9.4 Build 與 Deploy 指令

在環境設定的「Deployments」區塊填寫。兩者都有 **15 分鐘逾時限制**。

**Build commands**（PHP／後端建置區塊）—— build 階段對檔案系統的變更**會**保留，所以所有快取都放這裡：

```shell
composer install --no-dev --optimize-autoloader
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan filament:optimize
```

> 官方明示：`php artisan optimize` / `config:cache` **應該在 build 階段執行，而非 deploy 階段**。
> 前端資產由 Cloud 的前端建置設定處理（預設 `npm run build`）；本專案有 `package-lock.json`，
> 若需自訂請用 `npm ci && npm run build`。

**Deploy commands**（新版本上線前執行）—— deploy 階段對檔案系統的變更**不會**保留：

```shell
php artisan migrate --force
```

官方明列**不該**放進 build／deploy 指令的項目：

| 指令 | 原因 |
| --- | --- |
| `php artisan optimize:clear` | 會清掉快取並造成非預期行為（尤其與佇列相關） |
| `php artisan storage:link` | deploy 階段的檔案系統變更不保留，符號連結不會存在。本專案用 object storage，本就不需要 |
| `php artisan queue:restart` | Cloud 每次部署後自動重啟 worker |

### 9.5 一次性初始化

**順序很重要**——`APP_KEY` 與 passphrase 必須在第一次 `migrate` 與建金鑰**之前**就位。

1. **本機**產生兩個值並貼進 Cloud 環境變數面板（見 9.3）：

   ```bash
   php artisan key:generate --show                    # → APP_KEY
   php artisan licensing-passphrase:generate --show   # → LICENSING_KEY_PASSPHRASE
   ```

2. **部署一次**。deploy command 的 `migrate --force` 會建好所有資料表（含 `licensing_keys`）。

3. 於環境的 **Commands 分頁**依序執行（指令必須非互動，且不得超過 30 分鐘）：

   ```bash
   php artisan licensing:keys:make-root                   # 產 root 金鑰對，私鑰加密進 DB。只跑一次
   php artisan licensing:keys:issue-signing --days=3650   # 由 root 簽發 10 年期 signing key
   php artisan licensing:keys:list                        # 確認 root 與 signing 均 active
   ```

4. **建立後台管理員**。`make:filament-user` 是互動式指令，**在 Cloud 的 Commands 分頁無法使用**，改用非互動寫法：

   ```bash
   php artisan tinker --execute 'App\Models\User::create(["name"=>"Admin","email"=>"admin@example.com","password"=>bcrypt("<強密碼>")]);'
   ```

   > 若已安裝 Laravel Cloud CLI，亦可用 `cloud tinker {environment} --code='...' -n`。
   > 建立後請立即登入 `/admin` 改密碼。

5. **建立素材加密金鑰（CEK）**，每個產品／版本一把：

   ```bash
   php artisan content-key:create english-v1
   php artisan content-key:list                       # 含明文金鑰，提供給 App 開發端加密素材
   ```

   接著到後台 **授權管理 → License Scope** 把 CEK 指派到對應 Scope。**Scope 未綁 CEK，client 呼叫 activate 會失敗。**

6. **匯出 root 公鑰交給 App 端內嵌**：

   ```bash
   php artisan licensing:keys:export --include-chain
   ```

   詳見 [`docs/licensing-keys.md` 第 3.3 節](licensing-keys.md)。

### 9.6 排程（取代第 7 節的 OS cron）

Cloud 內建排程器，不需要任何 cron 設定：

1. 點開環境的 **App compute cluster**
2. 開啟 **Scheduler** 開關
3. 儲存並**重新部署**

之後 Cloud 會每分鐘自動呼叫 `php artisan schedule:run`。若希望排程跑在與網頁流量分離的機器上，也可改在 Worker cluster 開啟同一個開關。

> **開了 Scale to Zero 時不是每分鐘喚醒**。Cloud 會依 `schedule:list` 的時刻表**只在排程到期時喚醒** app（開關的說明文字即「wake your app to execute tasks when scheduled」），跑完後依 Sleep after 設定睡回去。以本專案每日 02:00 一次、sleep 5 分鐘計，每天僅多約 5～6 分鐘 compute。
>
> 另外**不要**為了排程去開 **Wake up interval** —— 那是給 Background processes（如常駐 queue worker）用的週期性喚醒，會實質侵蝕 Scale to Zero。

**本專案相關注意事項：**

- **多 replica 安全** — Cloud 文件要求多 replica 時排程必須使用 `onOneServer()`，否則每個 replica 都會各跑一次。`routes/console.php` 已經有 `onOneServer()` 與 `withoutOverlapping()`，這點**已符合**。
- **Scale to Zero 的喚醒依據** — Cloud 靠 `php artisan schedule:list` 的輸出決定何時喚醒環境，而**這份輸出只在部署當下擷取**。因此更動 `config/licensing.php` 的 `scheduler.check_expirations.time`（或啟停排程）後，**必須重新部署**才會生效。
- **睡眠不受影響** — 官方建議排程間隔不可短於 sleep timeout，否則環境永遠睡不著。本專案每日僅 02:00 執行一次，無此問題。
- **cache store 查詢** — `withoutOverlapping()`／`onOneServer()` 會查詢環境的 cache store，頻繁執行可能讓資料庫無法休眠。本專案一天一次，影響可忽略。

**驗證**（Commands 分頁）：

```bash
php artisan schedule:list              # 確認排程已註冊、下次執行時刻正確
php artisan licensing:check-expirations # 手動觸發一次，預期輸出「轉入 expired: N」
```

### 9.7 日誌：`api` channel 需要調整（待決策）

`config/logging.php:130-135` 的 `api` channel 是 `daily` driver，寫入
`storage_path('logs/api.log')`，保留 30 天。API 層的完整 exception（被遮蔽成
`SERVER_ERROR` 的那些）都記在這裡。

**在 Cloud 上這些日誌會遺失**：檔案系統是 ephemeral 且每個 replica 各自獨立，
redeploy 即清空，且同一批錯誤會散落在不同 replica 上無法彙整。

建議做法（尚未實作，需決定）：

- 啟用 Cloud 的 **Nightwatch 整合**（`laravel/nightwatch` `^1.26` 已在 `composer.json`）。啟用後 Cloud 會自動注入 `LOG_CHANNEL=stack`、`LOG_STACK=laravel-cloud-socket,nightwatch`、`NIGHTWATCH_TOKEN`
- 將 `api` channel 由單純的 `daily` 改為同時輸出到 `stderr`／`laravel-cloud-socket`，讓 API 錯誤進入 Cloud 的日誌檢視與 Nightwatch

> 在調整前，正式站的 API 錯誤只能透過 Nightwatch 的 exception 追蹤查看，
> `storage/logs/api-*.log` 不可依賴。

### 9.8 上線驗收清單

| # | 檢查項 | 方法 |
| --- | --- | --- |
| 1 | 後台可登入 | 瀏覽 `https://<domain>/admin` 並登入 |
| 2 | root 與 signing key 均 active | Commands：`php artisan licensing:keys:list` |
| 3 | 排程已註冊且時刻正確 | Commands：`php artisan schedule:list` |
| 4 | 每個 LicenseScope 都已綁 CEK | 後台 License Scope 列表逐一確認 |
| 5 | **圖片可上傳到 private R2 bucket** | 後台新增／編輯 Wordwall 分類並上傳圖片；若出現 `NotImplemented` 即為 9.2 所述的 ACL 問題 |
| 6 | 圖片轉址端點正常 | 帶 bearer token 呼叫 `GET /api/v1/wordwall-categories/{id}/image`，應回 302 且 Location 指向 R2 presigned 網址 |
| 7 | activate → validate 端對端可通 | 用 `tests/e2e_activate_verify.php` 或 App staging build 實測 |
| 8 | App 內嵌的 root 公鑰與正式站同一對 | 確認 App 的 `_prodRootPublicKey`／`_prodRootKid` 來自**正式站**的 `licensing:keys:export` |
| 9 | `APP_DEBUG=false` | 故意打一個會 500 的 API 請求，確認回傳的是 `{"success":false,"error":{"code":"SERVER_ERROR",...}}` 而非 stack trace |
| 10 | passphrase 已備份 | 確認 `LICENSING_KEY_PASSPHRASE` 已存入密碼管理器 |

### 9.9 Cloud 專屬疑難排解

| 症狀 | 可能原因 |
| --- | --- |
| Redeploy 後所有裝置無法啟用 | keystore 落回 `files` driver，私鑰隨 `storage/` 被清。確認 `config/licensing.php` 的 `crypto.keystore.driver` 為 `database` |
| 排程改了時間卻沒生效 | Cloud 只在部署時擷取 `schedule:list`，需重新部署 |
| 排程完全沒跑 | App compute cluster 的 Scheduler 開關未開，或開了但未重新部署 |
| 圖片上傳回 `NotImplemented` | R2 不支援 per-object ACL，見 9.2 |
| 圖片上傳成功但後台預覽 404 | bucket 附加後未重新部署，或 `AWS_*` 被自訂變數覆蓋 |
| 統計數字浮報但 API 驗證正常 | 排程未執行（`status` 欄位未轉換）。API 用 `isExpired()` 即時判斷故不受影響 |
| `storage/logs/api-*.log` 找不到內容 | 預期行為，見 9.7 |

---

## 部署速查（精簡版）

### Laravel Cloud（正式站）

```bash
# 1. 本機產生兩個機密，貼進 Cloud 環境變數面板（詳見 9.3）
php artisan key:generate --show                    # → APP_KEY
php artisan licensing-passphrase:generate --show   # → LICENSING_KEY_PASSPHRASE

# 2. Cloud 環境設定 → Deployments
#    Build commands：
#      composer install --no-dev --optimize-autoloader
#      php artisan config:cache && php artisan route:cache
#      php artisan view:cache && php artisan filament:optimize
#    Deploy commands：
#      php artisan migrate --force

# 3. App compute cluster → 開啟 Scheduler 開關 → 儲存並重新部署

# 4. 部署後於 Commands 分頁執行（依序）
php artisan licensing:keys:make-root
php artisan licensing:keys:issue-signing --days=3650
php artisan licensing:keys:list
php artisan tinker --execute 'App\Models\User::create(["name"=>"Admin","email"=>"admin@example.com","password"=>bcrypt("<強密碼>")]);'
php artisan content-key:create <name>
php artisan licensing:keys:export --include-chain   # root 公鑰交給 App 內嵌

# 5. 驗收：見 9.8
```

### 自管主機 / Sail

```bash
# 1. 應用程式初始化
vendor/bin/sail artisan key:generate
vendor/bin/sail artisan licensing-passphrase:generate
vendor/bin/sail artisan migrate --force

# 2. 後台登入
vendor/bin/sail artisan make:filament-user

# 3. 授權簽章（API 必要）
vendor/bin/sail artisan licensing:keys:make-root
vendor/bin/sail artisan licensing:keys:issue-signing --days=3650

# 4. 素材加密金鑰（每個版本一把）
vendor/bin/sail artisan content-key:create <name>

# 5. Production 優化
vendor/bin/sail artisan config:cache
vendor/bin/sail artisan route:cache
vendor/bin/sail artisan view:cache
vendor/bin/sail artisan filament:optimize

# 6. 排程觸發（OS 層一次性設定，依部署環境擇一，詳見第 7 節）
#   Linux / macOS：crontab -e
#     * * * * * cd /path/to/project && php artisan schedule:run >> /dev/null 2>&1
#   Windows（PowerShell，以 Admin 執行）：
#     schtasks /Create /SC MINUTE /MO 1 /TN "LaravelSchedule_WilsonAdmin" ^
#       /TR "docker compose -f C:\path\docker-compose.yml exec -T laravel.test php artisan schedule:run" ^
#       /RU SYSTEM /F
# 驗證：vendor/bin/sail artisan schedule:list
```
