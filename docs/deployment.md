# 新機器部署指令清單

本文件整理本專案首次部署到新機器時，為了讓**管理者可登入 Filament 後台**、以及讓**客戶端 App 可呼叫授權／素材 API**，所需執行的 artisan 指令。

所有指令都透過 Sail 執行。若部署環境未使用 Sail，將前綴替換為 `php` 即可。

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

- `DB_*` — 資料庫連線
- `APP_URL` — 對外網址
- `APP_ENV=production`、`APP_DEBUG=false`
- `LICENSING_KEY_SALT`（可選）— 授權碼雜湊鹽值，未設定會使用 `APP_KEY`
- `LICENSING_TOKEN_ISSUER`（可選）— PASETO token issuer，未設定會使用預設值 `wilson-ebook-admin`

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
| `vendor/bin/sail artisan migrate --force` | 建立所有資料表（users、licenses、license_scopes、license_usages、content_encryption_keys、licensing_key_registry、licensing_signing_keys 等）。`--force` 在 production 環境必要 |

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
| `vendor/bin/sail artisan licensing:keys:make-root` | 產生根金鑰（Root Key）。會以 `LICENSING_KEY_PASSPHRASE` 加密私鑰後寫入 `licensing_key_registry`。**每台新機器只需執行一次** |
| `vendor/bin/sail artisan licensing:keys:issue-signing --days=3650` | 由根金鑰簽發一把有效期 10 年的 signing key，activate / validate 會挑選目前 active 的 signing key 來簽 PASETO。套件硬編碼預設為 30 天，這裡明確指定以免頻繁重簽 |
| `vendor/bin/sail artisan licensing:keys:list`（選） | 驗證用：列出目前所有金鑰與狀態，確認 root 與 signing key 均 active |

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
| `licensing:check-expirations` | 每日 02:00（`config('app.timezone')`，Asia/Taipei） | `Active` 且 `expires_at < now()` → `Grace`；`Grace` 且已過 grace days → `Expired` 並觸發 `LicenseExpired` event |

註冊位置：`routes/console.php`；啟用開關：`config('licensing.scheduler.check_expirations.enabled')`；執行時刻：`config('licensing.scheduler.check_expirations.time')`。

每次轉換會自動寫入 `audits` 表，`user_id` 為 `null`（代表系統觸發）。以 `WHERE user_id IS NULL` 可篩出所有系統自動變更。

### 部署時必須新增作業系統排程觸發

Laravel 的 `Schedule::command(...)` 只登錄排程清單，**實際觸發需要 OS 層每分鐘呼叫 `php artisan schedule:run`**；沒設這個觸發器，排程永遠不會執行。

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

預期輸出：`轉入 grace: N；轉入 expired: M`。

> `onOneServer()` 需要 cache driver 支援 atomic locks。本專案預設 `CACHE_STORE=database` 已支援，若改用 `file` driver 需移除此設定或改用 redis/memcached。

---

## 8. 進階運維指令（非首次部署）

以下指令在首次部署不需要，日常運維時視情況使用：

- `licensing:keys:rotate` — 輪替 signing key（`--reason=routine|compromised`）
- `licensing:keys:revoke {kid}` — 撤銷指定金鑰
- `licensing:keys:export` — 匯出公鑰供外部驗簽
- `licensing:offline:issue` — 簽發離線 token 給斷網環境的 client

---

## 部署速查（精簡版）

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
