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

## 7. 進階運維指令（非首次部署）

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
```
