# Wilson 電子書授權 API 串接文件

> 目標讀者：負責串接 Wilson 電子書授權系統的 App 端開發者（Flutter、iOS/Android native、CI 測試工具等）。
>
> 範圍：涵蓋所有對外開放的授權 API endpoint、請求／回應格式、錯誤碼、離線驗證與內容金鑰解包流程。
>
> 版本：v1（對應 `api/licensing/v1` 路由前綴）
>
> 最後更新：2026-04-10

---

## 目錄

1. [導讀](#1-導讀)
2. [共通規範](#2-共通規範)
3. [核心觀念](#3-核心觀念)
4. [Endpoint 詳解](#4-endpoint-詳解)
   - 4.1 [`GET /api/licensing/v1/health`](#41-get-apilicensingv1health)
   - 4.2 [`POST /api/licensing/v1/activate`](#42-post-apilicensingv1activate)
   - 4.3 [`POST /api/licensing/v1/validate`](#43-post-apilicensingv1validate)
5. [離線驗證與內容解密流程](#5-離線驗證與內容解密流程)
6. [錯誤處理策略建議](#6-錯誤處理策略建議)
7. [App 端完整串接流程（Flutter 參考）](#7-app-端完整串接流程flutter-參考)
8. [附錄](#8-附錄)

---

## 1. 導讀

### 1.1 這份文件為什麼存在

Wilson 電子書採用兩層架構的授權系統：

- **後端**：Laravel 12 + Filament v4 + `masterix21/laravel-licensing`，負責授權碼產生、狀態管理、PASETO token 簽發、ECDH content key wrapping。
- **App 端**：Flutter（`wilson-e-book-english`）、以及未來可能的 iOS/Android native 或其他平台。

為了讓 App 端能：

1. 啟用授權並取得離線可驗證的 token
2. 解出保護電子書內容的 content key
3. 週期性回報心跳與校驗授權狀態

後端提供了 3 個對外 API endpoint。這份文件是這些 endpoint 的**唯一 source of truth**。

### 1.2 Endpoint 總覽

| Method | URL | 用途 | 備註 |
|---|---|---|---|
| `GET` | `/api/licensing/v1/health` | 健康檢查 | 套件內建，不做商業邏輯 |
| `POST` | `/api/licensing/v1/activate` | 授權啟用、換發 token、取得 wrapped content key | 專案自訂（含 ECDH wrapping） |
| `POST` | `/api/licensing/v1/validate` | 驗證授權狀態、更新 heartbeat、合併 meta | 專案自訂 |

> ⚠️ **目前版本不支援 deactivate API**。後端 `routes/api.php` 只註冊了上述 3 個 endpoint。若 App 端呼叫 `POST /api/licensing/v1/deactivate`，會收到 Laravel 預設的 404 頁（**不是**本文件定義的統一錯誤格式）。要「登出」授權，請直接清除 App 本地儲存。

### 1.3 授權系統架構

```
┌─────────────────────────────────────────────────────────────┐
│                      LicenseScope                           │
│  (例：Wilson eBook English v1)                              │
│                                                             │
│  ├─ content_encryption_key_id ──► ContentEncryptionKey      │
│  │                                  (32-byte AES-256)       │
│  │                                                          │
│  └─ licenses ──────────────┐                                │
│                            │                                │
└────────────────────────────┼────────────────────────────────┘
                             │
              ┌──────────────┴───────────────┐
              ▼                              ▼
       ┌─────────────┐                ┌─────────────┐
       │  License A  │                │  License B  │
       │             │                │             │
       │ key_hash    │                │ key_hash    │
       │ status      │                │ status      │
       │ expires_at  │                │ expires_at  │
       │ max_usages  │                │ max_usages  │
       └──────┬──────┘                └──────┬──────┘
              │                              │
     ┌────────┴────────┐             ┌───────┴──────┐
     ▼                 ▼             ▼              ▼
 ┌────────┐       ┌────────┐    ┌────────┐    ┌────────┐
 │Usage 1 │       │Usage 2 │    │Usage 3 │    │Usage 4 │
 │(iPhone)│       │(iPad)  │    │(iPhone)│    │(Android)│
 └────────┘       └────────┘    └────────┘    └────────┘
  fingerprint     fingerprint    fingerprint   fingerprint
```

- **LicenseScope**：產品／版本的群組，一把 **content encryption key (CEK)** 掛在 Scope 上，供該 Scope 下所有 License 共用。
- **License**：單一授權實體，對應使用者拿到的授權碼（如 `A7KMR-3NPX9-BWD5H-TJ2QF`）。擁有狀態、到期日、最大座位數。
- **LicenseUsage**：一次「座位消耗」，通常等同一台裝置。每個裝置透過 `fingerprint` 綁定。
- **ContentEncryptionKey**：32-byte 的 AES-256 金鑰，從未離開後端；透過 ECDH wrap 後才交付給 App。

### 1.4 快速開始（3 分鐘驗證連線）

在 App 開發最初期，先用 curl 確認後端可達且版本正確：

```bash
curl -s https://license.wilson-ebook.com/api/licensing/v1/health | jq
```

預期輸出（節錄）：

```json
{
  "success": true,
  "data": {
    "status": "healthy",
    "checks": {
      "database": { "status": "ok" },
      "root_key": { "status": "ok", "kid": "root_..." },
      "signing_key": { "status": "ok", "kid": "signing_..." }
    }
  }
}
```

如果收到 `"status": "degraded"` 或 HTTP 5xx，請聯絡後端團隊——App 端 activate／validate 在此狀態下也會失敗。

---

## 2. 共通規範

### 2.1 Base URL

| 環境 | Base URL |
|---|---|
| Production | `https://license.wilson-ebook.com` |
| Local dev | `http://localhost`（Sail 預設）或依本機 `APP_URL` 設定 |

完整的 endpoint URL 為 `{Base URL}/api/licensing/v1/{endpoint}`。

**建議做法**：將 Base URL 外部化成建置時常數（例如 Flutter 的 `--dart-define=LICENSE_BASE_URL=...`），不要寫死在程式碼裡。

### 2.2 HTTP Header

所有 POST endpoint 都要求：

```
Content-Type: application/json; charset=utf-8
```

**不需要** `Authorization` header——本 API 不使用 Bearer token 或 API key。認證方式見下一節。

### 2.3 認證模型

本 API 沒有傳統意義上的「登入」。每次 `activate` / `validate` 呼叫都透過請求 body 中的兩個欄位識別呼叫者：

- `license_key`：使用者拿到的授權碼
- `fingerprint`：發起呼叫的裝置指紋（非 PII、穩定、可重現）

再搭配 ECDH ephemeral keypair 交換 content key，確保：

- Content key 絕不明文傳輸（forward secrecy）
- Server 簽出的 PASETO token 綁定 `license_key_hash + usage_fingerprint`，只有該裝置能驗證通過

Rate limit 設定（出自 `config/licensing.php`）：

| 端點類別 | 每分鐘限制 |
|---|---|
| `validate_per_minute` | 60 |
| `token_per_minute` | 20 |
| `register_per_minute` | 30 |

### 2.4 回應格式

#### 成功回應

HTTP 200，body 結構：

```json
{
  "success": true,
  "data": { ... }
}
```

`data` 內容依 endpoint 不同，見 Section 4。

#### 錯誤回應

HTTP 狀態依錯誤類別（400/403/404/409/410/422/423/500），body 結構：

```json
{
  "success": false,
  "error": {
    "code": "ERROR_CODE",
    "message": "Human-readable message",
    "details": { ... }
  }
}
```

- `code`：大寫 snake case 的錯誤碼，用於程式判斷（見 Section 6 錯誤碼總表）
- `message`：英文描述（可供 log，但**不要直接顯示給使用者**）
- `details`：僅在 `VALIDATION_FAILED` 時出現，含 Laravel validator 的 `errors()` 內容

> ⚠️ **特殊情況**：若呼叫了後端未註冊的路徑（例如 `/deactivate`），會收到 Laravel 預設的 404 HTML 或一般 JSON 404，**不會**遵循上述結構。App 端應把這種 case 視為「網路 / 設定問題」而非 API 錯誤。

#### 🛡️ 5xx 錯誤遮蔽政策

為降低內部資訊外洩風險，**所有 HTTP 5xx 回應一律遮蔽成 generic `SERVER_ERROR`**，不管後端實際遇到什麼錯誤都回相同內容：

```json
{
  "success": false,
  "error": {
    "code": "SERVER_ERROR",
    "message": "Server error"
  }
}
```

App 端**無法**從 response 區分具體原因（是 offline token 未啟用？scope 沒綁 content key？ECDH wrap 失敗？PASETO 簽發失敗？資料庫斷線？PHP fatal？）。這是刻意的資安設計——不暴露設定細節、exception 訊息、stack trace。

後端仍會把完整錯誤（`internal_code`、`exception_class`、`exception_message`、`exception_trace`）記錄到 `api` log channel（`storage/logs/api-YYYY-MM-DD.log`），供後端團隊查詢排錯。若 App 端反應「一直拿到 SERVER_ERROR」，請同時提供發生時間，便於後端比對 log。

所有 4xx 錯誤（`VALIDATION_FAILED`、`INVALID_KEY`、`SUSPENDED_LICENSE`、`EXPIRED_LICENSE`、`USAGE_LIMIT_REACHED` 等）**不在此政策範圍內**，仍會回傳具體 `code` 與 `message`，App 端可依此做分流處理。

### 2.5 時區

- 後端顯示與 log 時區：`Asia/Taipei`（GMT+8）
- 資料庫儲存與 API 回傳：**UTC**（ISO 8601 with offset，例如 `"2026-04-10T07:43:52+00:00"`）

App 端請以 `DateTime.parse` 等支援 ISO 8601 offset 的 API 解析，並校正本機時區偏移（見 [5.4 Content key 銷毀與重用](#54-content-key-銷毀與重用) 中的 `serverTimeDelta` 技巧）。

### 2.6 關鍵常數（後端寫死，必須對齊）

App 端解包 content key 與驗證 PASETO token 時，下列常數必須與後端**完全一致**，否則驗證 / 解密都會失敗：

| 名稱 | 值 | 用途 |
|---|---|---|
| Wrap 演算法識別 | `X25519+HKDF-SHA256+XChaCha20-Poly1305` | `wrapped_content_key.alg` 欄位 |
| HKDF info prefix | `wilson-content-key-wrap-v1` | HKDF-SHA256 `info` 的前綴 |
| AEAD AAD | `wilson-content-key-wrap-v1` | XChaCha20-Poly1305 的 AAD |
| Token issuer | `wilson-ebook-admin` | PASETO claims `iss` 欄位 |
| PASETO version | `v4.public` | Ed25519 簽章 |
| Ephemeral key 長度 | 32 bytes (X25519) | `client_ephemeral_public_key` 解碼後 |
| Nonce 長度 | 24 bytes (XChaCha20) | `wrapped_content_key.nonce` 解碼後 |
| Poly1305 tag 長度 | 16 bytes | `wrapped_content_key.ciphertext` 結尾 |
| HKDF salt 長度 | 32 bytes | `wrapped_content_key.salt` 解碼後 |
| Wrap key 長度 | 32 bytes | HKDF 輸出 |

這些常數來自 `app/Services/ContentKeyWrapper.php:30-34` 與 `config/licensing.php` 的 `offline_token.issuer` 設定。

### 2.7 授權碼格式（⚠️ 最容易踩的坑）

> **🚨 重要：送出 `license_key` 前必須移除所有 dash（`-`）字元。**

後端 `App\Services\LicenseKeyGenerator` 產生的授權碼是 **20 字元連續字串**（例如 `A7KMR3NPX9BWD5HTJ2QF`）。`LicenseKeyGenerator::format()` 只是顯示用的便利方法，把字串每 5 個字元插入一個 `-`，變成 `A7KMR-3NPX9-BWD5H-TJ2QF`——後台與信件預設都會顯示這個格式。

但後端 `License::hashKey()` 是對原始字串做 `hash_hmac('sha256', $key, $salt)`，**沒有 normalize**（不移除 dash）：

```php
// vendor/masterix21/laravel-licensing/src/Models/License.php:142-145
public static function hashKey(string $key): string
{
    return hash_hmac('sha256', $key, static::keySalt());
}
```

也就是說，資料庫中 `licenses.key_hash` 儲存的是**去 dash 版本**的 HMAC。如果 App 端把使用者看到的顯示格式（帶 dash）直接送出，`findByKey()` 會因為 hash 對不上而回 `INVALID_KEY`（404）。

#### 正確做法

App 端收到使用者輸入後，**務必**先移除所有 dash 再組 request body：

```dart
// Flutter 端範例（lib/core/license/license_service.dart:74）
body['license_key'] = licenseKey.replaceAll('-', '');
```

```swift
// iOS Swift 範例
let normalized = licenseKey.replacingOccurrences(of: "-", with: "")
```

```kotlin
// Android Kotlin 範例
val normalized = licenseKey.replace("-", "")
```

```bash
# curl 範例：直接用 20 字元無 dash 格式
curl -X POST https://license.wilson-ebook.com/api/licensing/v1/activate \
  -H "Content-Type: application/json" \
  -d '{"license_key": "A7KMR3NPX9BWD5HTJ2QF", "fingerprint": "...", "client_ephemeral_public_key": "..."}'
```

#### 字元集

授權碼字元集為 `ABCDEFGHJKMNPQRSTVWXYZ23456789`（去除易混淆的 `O/0/I/1/L/U`），所以使用者輸入時不會遇到這些字元。如果 App 端要做輸入框 validation，可以用 regex：

```
^[A-HJ-NP-TV-Z2-9]{20}$  # 去 dash 後的 20 字元
```

或是帶 dash 的顯示格式：

```
^[A-HJ-NP-TV-Z2-9]{5}(-[A-HJ-NP-TV-Z2-9]{5}){3}$
```

---

## 3. 核心觀念

### 3.1 Fingerprint（裝置指紋）

`fingerprint` 是一個 **非 PII、穩定、可重現** 的字串，用於在 License 下唯一識別一個 Usage（=一個座位）。

#### 設計原則

1. **穩定**：同一台裝置，每次呼叫都應得到相同值；App 重灌、OS 升級不應改變（理想情況）
2. **可重現**：不需連網、不依賴使用者登入帳號
3. **非 PII**：不要直接用 email、使用者名稱、手機號碼
4. **裝置層級**：跟 user 無關，同一台裝置給不同使用者用都是同一個指紋

#### 各平台建議來源

| 平台 | 建議來源 | 備註 |
|---|---|---|
| iOS | `UIDevice.current.identifierForVendor?.uuidString` | Apple 官方，同一開發者帳號下的 App 共享 |
| Android | MAC address（NetworkInterface + sysfs fallback） | Flutter 專案目前採用；見 `wilson-e-book-english/android/.../DeviceFingerprintPlugin.kt` |
| macOS | `IOPlatformUUID`（`ioreg -d2 -c IOPlatformExpertDevice`） | |
| Windows | `reg query HKLM\SOFTWARE\Microsoft\Cryptography /v MachineGuid` | |
| Linux | `/etc/machine-id` 或 `/var/lib/dbus/machine-id` | |

> 目前 Wilson Flutter 端（`wilson-e-book-english`）只實作了 Android 的 MAC address 取得（`DeviceFingerprintPlugin.kt`）。其他平台需要時另外補充。

#### Fingerprint 長度與格式

後端沒有強制長度，但建議：

- 格式：**可列印的 ASCII 字串**（UTF-8 亦可）
- 長度：< 255 字元（避免 MySQL 索引問題）
- 可以是原始 UUID、MAC address、或任意字串的 SHA-256 hex 輸出

後端把 `fingerprint` 存在 `license_usages.usage_fingerprint` 欄位，且會繫結進 PASETO claim `usage_fingerprint` 與 HKDF `info`，因此**同一裝置每次呼叫必須給完全相同的字串**（包括大小寫、分隔符）。

### 3.2 License 狀態生命週期

後端 License 有 6 種狀態（`LucaLongo\Licensing\Enums\LicenseStatus`）：

```
              ┌────────────────────┐
              │                    │
              ▼                    │
         ┌─────────┐              │
   ┌────►│ pending │              │
   │     └────┬────┘              │
   │          │ activate()        │
   │          ▼                   │
   │     ┌─────────┐              │
   │     │ active  │──────────┐   │
   │     └────┬────┘          │   │
   │          │ 到期           │   │
   │          ▼               │   │
   │     ┌─────────┐          │   │
   │     │  grace  │──────────┤   │
   │     └────┬────┘          │   │
   │          │ 寬限期結束     │   │
   │          ▼               │   │
   │     ┌─────────┐          │   │
   │     │ expired │          │   │
   │     └─────────┘          │   │
   │                          │   │
   │                  suspend │   │
   │                  cancel  │   │
   │                          ▼   │
   │                    ┌───────────┐
   └────────────────────│ suspended │
                        │ cancelled │
                        └───────────┘
```

| 狀態 | 涵義 | activate 行為 | validate 行為 |
|---|---|---|---|
| `pending` | 剛建立，尚未啟用 | ✅ 會自動轉為 `active` | ❌ `LICENSE_NOT_ACTIVE` |
| `active` | 正常使用中 | ✅ 成功 | ✅ 成功 |
| `grace` | 已到期但在寬限期內 | ✅ 成功（含 `grace_until` claim） | ✅ 成功 |
| `expired` | 寬限期結束 | ❌ `EXPIRED_LICENSE` | ❌ `EXPIRED_LICENSE` |
| `suspended` | 被管理員暫停 | ❌ `SUSPENDED_LICENSE` | ❌ `SUSPENDED_LICENSE` |
| `cancelled` | 被管理員取消 | ❌ `SUSPENDED_LICENSE` | ❌ `SUSPENDED_LICENSE` |

**關鍵行為**：`activate` 會自動將 `pending → active`，所以 App 端不需要另外呼叫「啟用」API；第一次 `activate` 就是啟用。

### 3.3 Usage 狀態生命週期

LicenseUsage 只有兩種狀態：

| 狀態 | 涵義 |
|---|---|
| `active` | 座位被占用中 |
| `revoked` | 座位已釋出（被解除綁定） |

revoked 後，若再用**同 fingerprint** 對同 License 呼叫 `activate`，`UsageRegistrar` 會建立新的 usage 記錄；舊 revoked 記錄保留供 audit。

### 3.4 永久授權（`expires_at = null`）

後端支援 `expires_at = null` 的永久授權：

- License 永遠不會進入 `grace` 或 `expired` 狀態
- `activate` 仍會簽出 PASETO token，但 token TTL 設為 **36500 天**（100 年）
- Token payload **不含** `license_expires_at` 與 `grace_until` claim

實作常數見 `app/Http/Controllers/Api/LicenseController.php:22`：

```php
private const PERPETUAL_TOKEN_TTL_DAYS = 36500;
```

App 端判斷方式：

```dart
// Dart 範例
final isPermanent = license.expiresAt == null;
```

或直接看 PASETO claim 是否缺少 `license_expires_at`。

### 3.5 Offline Token TTL 規則

`activate` 回應中的 `token_expires_at` 是依以下規則計算：

```
有到期日（license.expires_at != null）：
  ttl_days = ceil((license.expires_at - now).inDays)
  ttl_days = max(ttl_days, 1)  // 至少 1 天

無到期日（license.expires_at == null）：
  ttl_days = 36500  // ≈ 100 年
```

**設計動機**：確保 offline token 不會活得比 License 本身久。若 License 只剩 3 天，token 最多 3 天有效；若 License 永久，token 也實質永久。

後端實作見 `app/Http/Controllers/Api/LicenseController.php:110-112`：

```php
$ttlDays = $license->expires_at !== null
    ? max(1, (int) ceil(now()->diffInDays($license->expires_at, absolute: true)))
    : self::PERPETUAL_TOKEN_TTL_DAYS;
```

---

## 4. Endpoint 詳解

### 4.1 `GET /api/licensing/v1/health`

| 項目 | 內容 |
|---|---|
| Method | `GET` |
| URL | `{Base URL}/api/licensing/v1/health` |
| Route name | `licensing.health` |
| Controller | `LucaLongo\Licensing\Http\Controllers\Api\HealthController@show`（套件內建） |
| 認證 | 不需要 |
| 副作用 | 無 |

#### 用途

確認後端授權服務、資料庫、Root Key、Signing Key 都可用。建議 App 端：

- App 啟動時呼叫一次作為 smoke test（可略過，不影響主流程）
- 遇到 activate / validate 長期失敗時用來診斷是「本地網路」還是「後端掛了」

#### 請求

無 body、無 header 要求。

```bash
curl https://license.wilson-ebook.com/api/licensing/v1/health
```

#### 成功回應

HTTP 200：

```json
{
  "success": true,
  "data": {
    "status": "healthy",
    "checks": {
      "database": {
        "status": "ok"
      },
      "root_key": {
        "status": "ok",
        "kid": "root_01JABCDEFGHIJKLMNOPQR",
        "valid_until": "2027-04-10T15:43:52+08:00"
      },
      "signing_key": {
        "status": "ok",
        "kid": "signing_01JABCDEFGHIJKLMNOPQR",
        "valid_until": "2027-04-10T15:43:52+08:00"
      }
    }
  }
}
```

#### Degraded 回應

若任一檢查失敗，仍回 HTTP 200，但 `status = "degraded"` 且對應 check 的 `status = "error"` 並帶 `message`：

```json
{
  "success": true,
  "data": {
    "status": "degraded",
    "checks": {
      "database": {
        "status": "error",
        "message": "SQLSTATE[HY000] [2002] Connection refused"
      },
      "root_key": { "status": "ok", "kid": "root_...", "valid_until": "..." },
      "signing_key": { "status": "ok", "kid": "signing_...", "valid_until": "..." }
    }
  }
}
```

**App 端建議處理**：若 `status != "healthy"`，**不應**繼續嘗試 activate／validate；直接顯示「授權服務暫時無法使用」並引導使用者稍後重試或聯絡客服。

#### Dart 範例

Flutter 專案目前沒有呼叫 health endpoint，但若要加上，可以這樣寫：

```dart
Future<bool> isLicenseServerHealthy() async {
  try {
    final response = await http
        .get(Uri.parse('$baseUrl/api/licensing/v1/health'))
        .timeout(const Duration(seconds: 5));
    if (response.statusCode != 200) return false;
    final json = jsonDecode(response.body) as Map<String, dynamic>;
    return json['data']?['status'] == 'healthy';
  } catch (_) {
    return false;
  }
}
```

---

### 4.2 `POST /api/licensing/v1/activate`

| 項目 | 內容 |
|---|---|
| Method | `POST` |
| URL | `{Base URL}/api/licensing/v1/activate` |
| Route name | `licensing.activate` |
| Controller | `App\Http\Controllers\Api\LicenseController@activate`（專案自訂） |
| 認證 | 不需要（透過 `license_key + fingerprint + ECDH`） |
| 副作用 | ① `pending` 狀態自動轉為 `active`；② 可能建立新 `LicenseUsage`；③ 更新 `last_seen_at`；④ 簽發新 PASETO token |

#### 用途

App 端向後端提交授權碼、裝置指紋與 ephemeral X25519 public key，取得：

- PASETO v4.public offline token（含 wrapped content key claim）
- 驗證 token 所需的 public key bundle（signing + root）
- License / Usage 的最新狀態資訊

**這是本 API 最複雜的 endpoint**，建議先仔細讀過 Section 5（離線驗證流程）再回來看細節。

#### 請求

**Headers**：

```
Content-Type: application/json; charset=utf-8
```

**Body schema**：

| 欄位 | 型別 | 必填 | 驗證規則 | 說明 |
|---|---|---|---|---|
| `license_key` | string | ✅ | `required, string` | 使用者授權碼，**必須去 dash**。例：`A7KMR3NPX9BWD5HTJ2QF` |
| `fingerprint` | string | ✅ | `required, string` | 裝置指紋，見 [3.1 Fingerprint](#31-fingerprint裝置指紋) |
| `client_ephemeral_public_key` | string | ✅ | `required, string`（base64）；解碼後必須是 **32 bytes** | Client 端此次 activate 用的臨時 X25519 public key。**每次呼叫必須產生新的 keypair**，不可重用 |
| `metadata` | object | ❌ | `nullable, array` | 自訂欄位，會 merge 進 `usage.meta`（例：OS 版本、App 版本） |

**Body 範例**：

```json
{
  "license_key": "A7KMR3NPX9BWD5HTJ2QF",
  "fingerprint": "AA:BB:CC:DD:EE:FF",
  "client_ephemeral_public_key": "hBM1p5VOlI79cGCX3Z2FfVe6q7y0Q2RtIxdCC5TnYAE=",
  "metadata": {
    "os": "Android",
    "os_version": "14",
    "app_version": "1.2.3"
  }
}
```

**curl 範例**：

```bash
curl -X POST https://license.wilson-ebook.com/api/licensing/v1/activate \
  -H "Content-Type: application/json; charset=utf-8" \
  -d '{
    "license_key": "A7KMR3NPX9BWD5HTJ2QF",
    "fingerprint": "AA:BB:CC:DD:EE:FF",
    "client_ephemeral_public_key": "hBM1p5VOlI79cGCX3Z2FfVe6q7y0Q2RtIxdCC5TnYAE="
  }'
```

> ⚠️ 上述 `client_ephemeral_public_key` 只是範例字串；實際使用請用 `X25519().newKeyPair()` 之類的 API 產生，並把 public key 以 base64 編碼傳送。參考 `wilson-e-book-english/lib/core/license/content_key_wrapper.dart:52-56`。

#### 成功回應

HTTP 200：

```json
{
  "success": true,
  "data": {
    "license": {
      "id": "01JABCDEFGHIJKLMNOPQRSTUVW",
      "name": "Wilson eBook English Basic",
      "status": "active",
      "activated_at": "2026-04-10T07:43:52+00:00",
      "expires_at": "2027-04-10T07:43:52+00:00",
      "max_usages": 1,
      "active_usages": 1,
      "available_seats": 0,
      "features": [],
      "entitlements": []
    },
    "usage": {
      "id": 123,
      "fingerprint": "AA:BB:CC:DD:EE:FF",
      "status": "active",
      "registered_at": "2026-04-10T07:43:52+00:00",
      "last_seen_at": "2026-04-10T07:43:52+00:00"
    },
    "token": "v4.public.eyJraWQiOiJzaWduaW5nX...略...c2lnbmF0dXJl.eyJraWQiOi...",
    "token_expires_at": "2027-04-10T07:43:52+00:00",
    "refresh_after": "2027-04-09T07:43:52+00:00",
    "force_online_after": "9999-12-31T23:59:59+00:00",
    "public_key_bundle": {
      "signing": {
        "kid": "signing_01JABCDEFGHIJKLMNOPQR",
        "public_key": "base64(ed25519 public key, 32 bytes)",
        "certificate": "base64(certificate signed by root key)",
        "valid_from": "2026-04-10T07:43:52+00:00",
        "valid_until": "2027-04-10T07:43:52+00:00"
      },
      "root": {
        "kid": "root_01JABCDEFGHIJKLMNOPQR",
        "public_key": "base64(ed25519 public key, 32 bytes)",
        "valid_from": "2026-04-10T07:43:52+00:00",
        "valid_until": "2027-04-10T07:43:52+00:00"
      },
      "issued_at": "2026-04-10T07:43:52+00:00"
    }
  }
}
```

**Token 內的 claims**（以 `v4.public` 簽章保護，App 端需離線驗證——見 [Section 5.2](#52-paseto-v4public-簽章驗證規格)）：

```json
{
  "kid": "signing_01JABCDEFGHIJKLMNOPQR",
  "license_id": 42,
  "license_key_hash": "base64(hmac-sha256 of license key)",
  "usage_fingerprint": "AA:BB:CC:DD:EE:FF",
  "status": "active",
  "max_usages": 1,
  "force_online_after": "9999-12-31T23:59:59+00:00",
  "licensable_type": null,
  "licensable_id": null,
  "license_expires_at": "2027-04-10T07:43:52+00:00",
  "wrapped_content_key": {
    "alg": "X25519+HKDF-SHA256+XChaCha20-Poly1305",
    "server_ephemeral_public_key": "base64(32 bytes)",
    "salt": "base64(32 bytes)",
    "nonce": "base64(24 bytes)",
    "ciphertext": "base64(variable, 結尾 16 bytes 為 poly1305 tag)",
    "aad": "wilson-content-key-wrap-v1"
  },
  "iat": 1712748232,
  "nbf": 1712748232,
  "exp": 1744284232,
  "sub": "42",
  "iss": "wilson-ebook-admin"
}
```

#### 成功條件分支

`activate` 根據 License 當下狀態有多種成功分支：

##### 分支 A：首次 activate（`pending → active`）

**觸發條件**：License 狀態為 `pending`（剛由後台建立，從未被啟用）

**行為**：後端自動呼叫 `$license->activate()` 轉為 `active`，然後繼續後續流程。

**回應差異**：`license.status = "active"`、`license.activated_at = now()`

##### 分支 B：重新 activate（同 fingerprint）

**觸發條件**：License 已 `active`，且 App 端用**相同** `fingerprint` 再次呼叫 `activate`

**行為**：`UsageRegistrar::register()` 找到現有 usage 後更新 `last_seen_at`（不新增座位）；簽發**新 token**、包裝**新 wrapped_content_key**（因為 server ephemeral keypair 每次都重新產生）

**回應差異**：`usage.id` 不變，但 `token`、`wrapped_content_key`、`refresh_after` 都是新值

**使用情境**：token 過期前換發、App 重灌後重新取得 token

##### 分支 C：同 License、新 fingerprint、未達 `max_usages`

**觸發條件**：License 已 `active`，但 `fingerprint` 不同，且現有 active usages 數 < `max_usages`

**行為**：新增一筆 `LicenseUsage`

**回應差異**：`usage.id` 是新值，`license.active_usages` +1，`license.available_seats` -1

##### 分支 D：有到期日授權

**觸發條件**：`license.expires_at != null`

**回應差異**：
- Token payload 含 `license_expires_at` claim
- `token_expires_at` = `min(now + 剩餘天數, 無限)`，永遠不超過 License 本身的到期日

##### 分支 E：永久授權（⚠️ 特殊）

**觸發條件**：`license.expires_at == null`

**回應差異**：
- `license.expires_at = null`（response 中明確為 `null`）
- Token payload **不含** `license_expires_at` claim
- Token payload **不含** `grace_until` claim
- `token_expires_at` = `now + 36500 天`

**App 端檢測**：

```dart
final isPermanent = result.license.expiresAt == null;
// 或
final isPermanent = !claims.containsKey('license_expires_at');
```

##### 分支 F：寬限期內 activate（`grace`）

**觸發條件**：License 已到期但在寬限期內（預設 14 天，見 `config/licensing.php` 的 `policies.grace_days`）

**回應差異**：
- `license.status = "grace"`
- `license.grace_days_remaining` 為剩餘天數（整數）
- Token payload 含 `grace_until` claim

**App 端建議**：顯示「授權即將到期，請儘速續約」警告，但仍允許使用。

#### 錯誤回應

| 錯誤碼 | HTTP | 觸發條件 | 建議 App 端處理 |
|---|---|---|---|
| `VALIDATION_FAILED` | 422 | 缺少必填欄位／型別錯誤 | 修正程式邏輯，不是使用者錯誤 |
| `INVALID_EPHEMERAL_KEY` | 400 | `client_ephemeral_public_key` base64 解碼失敗 或長度不是 32 bytes | 檢查 X25519 實作；不該發生在正常情況 |
| `INVALID_KEY` | 404 | License key 查無此筆（最常見原因：**沒移除 dash**） | 顯示「授權碼無效」，引導使用者重新輸入 |
| `SUSPENDED_LICENSE` | 423 | `license.status = suspended` 或 `cancelled` | 顯示「授權已被停用」，引導聯絡客服 |
| `EXPIRED_LICENSE` | 410 | 已過期且超出寬限期 | 顯示「授權已過期」，引導續約 |
| `LICENSE_NOT_ACTIVE` | 403 | 其他無法使用的狀態 | 顯示通用錯誤 |
| `USAGE_LIMIT_REACHED` | 409 | 已達 `max_usages` | 顯示「此授權已綁定其他裝置」 |
| `FINGERPRINT_CONFLICT` | 409 | unique scope 設為 `global` 時，同 fingerprint 已註冊在別的 License | 罕見 |
| `SERVER_ERROR` | 500 | **任何後端內部錯誤**（詳見下方） | 顯示「伺服器暫時無法使用」，可重試 1-2 次 |

##### `SERVER_ERROR` 涵蓋的內部情境

依 [2.4 5xx 錯誤遮蔽政策](#️-5xx-錯誤遮蔽政策)，以下所有情境在 HTTP 層都**無法區分**（response 永遠是 `{code: "SERVER_ERROR", message: "Server error"}`），但會以不同 `internal_code` 記錄到後端 `api` log：

| 內部分類（log 才看得到） | 觸發條件 | 根本原因 |
|---|---|---|
| `TOKEN_REQUIRED` | 後台 `config/licensing.php` 的 `offline_token.enabled = false` | 後台設定問題 |
| `MISSING_CONTENT_KEY` | License 對應的 LicenseScope 沒有綁定 `content_encryption_key_id` | 後台設定問題 |
| `WRAP_FAILED` | ECDH / HKDF / XChaCha20-Poly1305 任一步驟拋 Throwable | 後端密碼學實作 bug 或 libsodium 問題 |
| `TOKEN_ISSUE_FAILED` | PASETO 簽發失敗（通常是 signing key 找不到或 private key 無效） | 後端金鑰基礎設施問題 |
| `UNCAUGHT_EXCEPTION` | Controller 外層未捕獲的 Throwable（資料庫斷線、PHP fatal 等） | 由 `bootstrap/app.php` 的 exception handler 攔截 |

**錯誤回應範例**：

```json
// 404 INVALID_KEY
{
  "success": false,
  "error": {
    "code": "INVALID_KEY",
    "message": "License key is invalid or not found"
  }
}

// 400 INVALID_EPHEMERAL_KEY
{
  "success": false,
  "error": {
    "code": "INVALID_EPHEMERAL_KEY",
    "message": "client_ephemeral_public_key must be base64 of a 32-byte X25519 public key"
  }
}

// 409 USAGE_LIMIT_REACHED
{
  "success": false,
  "error": {
    "code": "USAGE_LIMIT_REACHED",
    "message": "License has reached maximum usages"
  }
}

// 500 SERVER_ERROR（任何 5xx 都是這個統一格式）
{
  "success": false,
  "error": {
    "code": "SERVER_ERROR",
    "message": "Server error"
  }
}

// 422 VALIDATION_FAILED
{
  "success": false,
  "error": {
    "code": "VALIDATION_FAILED",
    "message": "Request payload is invalid",
    "details": {
      "license_key": ["The license key field is required."]
    }
  }
}
```

#### Dart 範例

來自 `wilson-e-book-english/lib/core/license/license_service.dart:67-85`（精簡）：

```dart
Future<LicenseActivationResult> activate({
  required String licenseKey,
  required String fingerprint,
  required String clientEphemeralPublicKey,
  Map<String, dynamic>? metadata,
}) async {
  final body = <String, dynamic>{
    // ⚠️ 務必去除 dash
    'license_key': licenseKey.replaceAll('-', ''),
    'fingerprint': fingerprint,
    'client_ephemeral_public_key': clientEphemeralPublicKey,
  };
  if (metadata != null) {
    body['metadata'] = metadata;
  }

  final uri = Uri.parse('$baseUrl/api/licensing/v1/activate');
  final response = await http
      .post(
        uri,
        headers: const {'Content-Type': 'application/json; charset=utf-8'},
        body: jsonEncode(body),
      )
      .timeout(const Duration(seconds: 15));

  final json = jsonDecode(utf8.decode(response.bodyBytes)) as Map<String, dynamic>;

  if (response.statusCode == 200) {
    return LicenseActivationResult.fromJson(json);
  }

  // 解析統一錯誤格式
  final error = json['error'] as Map<String, dynamic>?;
  throw LicenseApiException(
    response.statusCode,
    error?['message'] as String? ?? '未知錯誤',
    errorCode: error?['code'] as String?,
  );
}
```

完整的 ephemeral keypair 產生 + activate 呼叫 + token 驗證 + content key 解包流程見 [Section 7](#7-app-端完整串接流程flutter-參考)。

#### 原始碼指引

| 關注點 | 檔案 | 行號 |
|---|---|---|
| Controller 入口 | `app/Http/Controllers/Api/LicenseController.php` | 32-127 |
| Ephemeral key 驗證 | 同上 | 41-48 |
| `TOKEN_REQUIRED` 前置檢查（避免白白消耗 seat） | 同上 | 67-73 |
| ECDH wrapping 呼叫 | 同上 | 93-106 |
| Token TTL 計算（永久授權特例） | 同上 | 108-112 |
| `extra_claims` 注入 `wrapped_content_key` | 同上 | 114-121 |
| Public key bundle 覆寫 | 同上 | 143-171 |
| `format($license)` 補上 `name` | 同上 | 129-135 |
| ECDH 實作 | `app/Services/ContentKeyWrapper.php` | 47-114 |
| PASETO 簽發（含 `extra_claims` 支援） | `app/Services/WilsonPasetoTokenService.php` | 36-131 |

---

### 4.3 `POST /api/licensing/v1/validate`

| 項目 | 內容 |
|---|---|
| Method | `POST` |
| URL | `{Base URL}/api/licensing/v1/validate` |
| Route name | `licensing.validate` |
| Controller | `App\Http\Controllers\Api\ValidateController@validateLicense`（專案自訂） |
| 認證 | 不需要 |
| 副作用 | ① 更新 `usage.last_seen_at`（heartbeat）；② 若帶 `data`，合併進 `usage.meta` |

#### 用途

App 端用來：

1. **週期驗證**：定時呼叫以確認 License 狀態仍為 `active`（例如每次 App 從背景回到前景、每天一次）
2. **Heartbeat**：更新 `usage.last_seen_at`，給後台管理員看「最近使用時間」
3. **回報 metadata**：把當前 app 版本、使用時數等資訊 merge 進 `usage.meta`

**`validate` 不會**：
- 簽發新 token（要換發需呼叫 `activate`）
- 傳送 wrapped content key
- 改變 License 狀態

#### 請求

**Headers**：

```
Content-Type: application/json; charset=utf-8
```

**Body schema**：

| 欄位 | 型別 | 必填 | 驗證規則 | 說明 |
|---|---|---|---|---|
| `license_key` | string | ✅ | `required, string` | 授權碼，**必須去 dash** |
| `fingerprint` | string | ✅ | `required, string` | 裝置指紋（必須與 activate 時相同） |
| `data` | object | ❌ | `nullable, array` | 自訂欄位，會 merge 進 `usage.meta` |

**Body 範例**：

```json
{
  "license_key": "A7KMR3NPX9BWD5HTJ2QF",
  "fingerprint": "AA:BB:CC:DD:EE:FF",
  "data": {
    "app_version": "1.2.4",
    "last_chapter": "chapter-7",
    "battery_level": 85
  }
}
```

**curl 範例**：

```bash
curl -X POST https://license.wilson-ebook.com/api/licensing/v1/validate \
  -H "Content-Type: application/json; charset=utf-8" \
  -d '{
    "license_key": "A7KMR3NPX9BWD5HTJ2QF",
    "fingerprint": "AA:BB:CC:DD:EE:FF"
  }'
```

#### 成功回應

HTTP 200：

```json
{
  "success": true,
  "data": {
    "license": {
      "id": "01JABCDEFGHIJKLMNOPQRSTUVW",
      "name": "Wilson eBook English Basic",
      "status": "active",
      "activated_at": "2026-04-10T07:43:52+00:00",
      "expires_at": "2027-04-10T07:43:52+00:00",
      "max_usages": 1,
      "features": [],
      "entitlements": []
    },
    "usage": {
      "id": 123,
      "fingerprint": "AA:BB:CC:DD:EE:FF",
      "status": "active",
      "registered_at": "2026-04-10T07:43:52+00:00",
      "last_seen_at": "2026-04-10T08:15:30+00:00"
    }
  }
}
```

**注意**：`validate` 回應**不包含** `token`、`token_expires_at`、`public_key_bundle` 等 activate 才有的欄位。

#### 成功條件分支

##### 分支 A：一般驗證

License `active`，已註冊 usage 存在且 `active`，回應如上。`usage.last_seen_at` 被更新為當前時間。

##### 分支 B：寬限期內驗證

License 狀態為 `grace`，回應中 `license.status = "grace"` 且 `license.grace_days_remaining` 有值。

**App 端建議**：顯示「授權即將到期」提醒。

##### 分支 C：帶 `data` 欄位

`data` 會以 PHP `array_merge` 語意合併進 `usage.meta`（相同 key 會被覆蓋）。回應中不直接回傳 `usage.meta`，若需確認可用後台查看。

#### 錯誤回應

| 錯誤碼 | HTTP | 觸發條件 | 建議 App 端處理 |
|---|---|---|---|
| `VALIDATION_FAILED` | 422 | 缺少必填欄位 | 修正程式邏輯 |
| `INVALID_KEY` | 404 | License 不存在（常見原因：**沒移除 dash**） | 顯示「授權碼無效」 |
| `SUSPENDED_LICENSE` | 423 | `suspended` 或 `cancelled` | 顯示「授權已被停用」並強制登出 |
| `EXPIRED_LICENSE` | 410 | 超出寬限期 | 顯示「授權已過期」並強制登出 |
| `LICENSE_NOT_ACTIVE` | 403 | 其他不可用狀態 | 顯示通用錯誤 |
| `FINGERPRINT_MISMATCH` | 403 | 此 License 下沒有符合該 fingerprint 的 active usage（可能已被 revoke 或換過裝置） | 強制重新呼叫 `activate`；若仍失敗則引導聯絡客服 |
| `SERVER_ERROR` | 500 | 資料庫斷線、PHP fatal 或其他未捕獲的 Throwable | 指數退避重試 1-2 次；詳見 [2.4 5xx 錯誤遮蔽政策](#️-5xx-錯誤遮蔽政策) |

**錯誤回應範例**：

```json
// 403 FINGERPRINT_MISMATCH
{
  "success": false,
  "error": {
    "code": "FINGERPRINT_MISMATCH",
    "message": "Fingerprint does not match an active usage for this license"
  }
}

// 410 EXPIRED_LICENSE
{
  "success": false,
  "error": {
    "code": "EXPIRED_LICENSE",
    "message": "License is expired"
  }
}

// 500 SERVER_ERROR
{
  "success": false,
  "error": {
    "code": "SERVER_ERROR",
    "message": "Server error"
  }
}
```

#### Dart 範例

來自 Flutter 端 `LicenseService` 介面的延伸（目前 Flutter 專案 `license_service.dart` 沒有 validate 方法，是透過 `activate` 重新驗證；以下是建議的補充寫法）：

```dart
Future<LicenseValidationResult> validate({
  required String licenseKey,
  required String fingerprint,
  Map<String, dynamic>? data,
}) async {
  final body = <String, dynamic>{
    'license_key': licenseKey.replaceAll('-', ''),
    'fingerprint': fingerprint,
  };
  if (data != null) {
    body['data'] = data;
  }

  final response = await http
      .post(
        Uri.parse('$baseUrl/api/licensing/v1/validate'),
        headers: const {'Content-Type': 'application/json; charset=utf-8'},
        body: jsonEncode(body),
      )
      .timeout(const Duration(seconds: 10));

  final json = jsonDecode(utf8.decode(response.bodyBytes)) as Map<String, dynamic>;

  if (response.statusCode == 200) {
    return LicenseValidationResult.fromJson(json['data']);
  }

  final error = json['error'] as Map<String, dynamic>?;
  throw LicenseApiException(
    response.statusCode,
    error?['message'] as String? ?? '未知錯誤',
    errorCode: error?['code'] as String?,
  );
}
```

#### 原始碼指引

| 關注點 | 檔案 | 行號 |
|---|---|---|
| Controller 入口 | `app/Http/Controllers/Api/ValidateController.php` | 15-52 |
| `guardLicenseState` 檢查 | `vendor/masterix21/laravel-licensing/src/Http/Controllers/Api/LicenseController.php` | 299-314 |
| `UsageRegistrar::findByFingerprint` | `vendor/masterix21/laravel-licensing/src/Services/EloquentUsageRegistrar.php` | - |
| Heartbeat 更新 | `vendor/masterix21/laravel-licensing/src/Licensing.php` | - |

---

## 5. 離線驗證與內容解密流程

這一節是本文件**最關鍵**的部分。`POST /activate` 拿到 `token` 和 `public_key_bundle` 之後，App 端要做三件事：

1. **驗證 PASETO token 的簽章**（確認是後端簽的，沒被中間人竄改）
2. **檢查 claims**（確認是簽給「這台裝置 + 這張授權」用的）
3. **從 `wrapped_content_key` claim 解出 32-byte content key**（才能解密電子書內容）

整個流程**完全離線可執行**——只要第一次有 `public_key_bundle` 存下來，之後都不需要連網，這也是為什麼叫「offline token」。

### 5.1 高層流程圖

```
                    [使用者輸入 license_key]
                             │
                             ▼
            ┌─────────────────────────────────┐
            │  並行做兩件事：                 │
            │  ① 取 fingerprint (MAC, IDFV…) │
            │  ② 產生 X25519 ephemeral keypair│
            │     (保留 secret key 在記憶體)  │
            └─────────────┬───────────────────┘
                          ▼
            POST /api/licensing/v1/activate
            { license_key, fingerprint,
              client_ephemeral_public_key }
                          │
                          ▼
            ┌─────────────────────────────────┐
            │  Server 回應：                  │
            │  - token (PASETO v4.public)     │
            │  - public_key_bundle            │
            │    (signing + root)             │
            └─────────────┬───────────────────┘
                          ▼
   ┌──────────────────────────────────────────┐
   │ 步驟 1: PASETO 簽章驗證                 │
   │   - 用 public_key_bundle.signing.       │
   │     public_key (Ed25519) 驗證 signature │
   │   - 驗證 PAE 計算                       │
   └──────────────────┬───────────────────────┘
                      ▼
   ┌──────────────────────────────────────────┐
   │ 步驟 2: Claims 檢查                      │
   │   - iss == "wilson-ebook-admin"         │
   │   - usage_fingerprint == 當前 fingerprint│
   │   - exp > now                           │
   │   - nbf <= now                          │
   └──────────────────┬───────────────────────┘
                      ▼
   ┌──────────────────────────────────────────┐
   │ 步驟 3: 解包 wrapped_content_key        │
   │   - 從 claims 取出 wrapped object       │
   │   - ECDH(client_secret, server_pub)     │
   │   - HKDF-SHA256 派生 wrap_key           │
   │   - XChaCha20-Poly1305 解密             │
   │   - → 32-byte content_key               │
   └──────────────────┬───────────────────────┘
                      ▼
   ┌──────────────────────────────────────────┐
   │ 步驟 4: 儲存 + 銷毀                      │
   │   - content_key → secure storage        │
   │   - ephemeral secret key → zeroize      │
   │   - shared secret → zeroize             │
   │   - wrap_key → zeroize                  │
   └──────────────────────────────────────────┘
```

### 5.2 PASETO v4.public 簽章驗證規格

#### Token 格式

PASETO v4.public 是一個用 `.` 分隔的字串：

```
v4.public.<base64url_nopad(message || signature)>.<base64url_nopad(footer)>
```

其中：

- `v4` / `public`：協定版本與類型
- `message`：UTF-8 JSON bytes（claims）
- `signature`：Ed25519 簽章，固定 64 bytes
- `footer`：JSON 格式，含 `kid` 和 `chain`

> ⚠️ **關鍵**：base64url 使用**無 padding**編碼（即不含 `=`）。解碼時需用支援無 padding 的 API，或自行補齊再解。

範例：

```
v4.public.eyJraWQiOiJzaWduaW5nXzAxSkFCQ0RFRkdISUpLTE1OT1BRIiwibGljZW5zZV9
pZCI6NDIsLi4uvQxqZkvK0TW2uaAB...c2lnbmF0dXJl.eyJraWQiOiJzaWduaW5nXzAxSkFC
Q0RFRkdISUpLTE1OT1BRIiwiY2hhaW4iOi4uLn0
```

#### 驗證步驟（語言中立 pseudo code）

```
INPUT:
  token_str       : string
  signing_pubkey  : 32-byte ed25519 public key (from public_key_bundle.signing.public_key)
  expected_issuer : "wilson-ebook-admin"
  expected_fp     : string (當前裝置 fingerprint)
  now             : current UTC time

STEP 1: 切分 token
  parts = token_str.split(".")
  assert parts.length >= 3
  assert parts[0] == "v4"
  assert parts[1] == "public"

STEP 2: 解碼 payload
  payload_bytes = base64url_decode_nopad(parts[2])
  assert len(payload_bytes) > 64
  message   = payload_bytes[0 .. len-64]    # JSON bytes
  signature = payload_bytes[len-64 .. len]  # 64 bytes

STEP 3: 解碼 footer (可能為空)
  if parts.length >= 4:
    footer = base64url_decode_nopad(parts[3])
  else:
    footer = empty_bytes

STEP 4: 組 Pre-Authentication Encoding (PAE)
  header_bytes       = utf8("v4.public.")
  implicit_assertion = empty_bytes    # v4 規格要求但通常為空

  pae = PAE([header_bytes, message, footer, implicit_assertion])

STEP 5: Ed25519 驗簽
  valid = Ed25519.verify(pae, signature, signing_pubkey)
  assert valid == true

STEP 6: 解析 claims
  claims = json_parse(utf8_decode(message))

STEP 7: 檢查 claims
  assert claims.iss == expected_issuer
  assert claims.usage_fingerprint == expected_fp
  assert now >= parse_iso8601(claims.nbf or now_fallback)
  assert now <  parse_iso8601(claims.exp)

RETURN claims
```

#### PAE (Pre-Authentication Encoding) 計算

PASETO 規格定義 PAE 為：

```
PAE(pieces) = LE64(len(pieces))
           || LE64(len(pieces[0])) || pieces[0]
           || LE64(len(pieces[1])) || pieces[1]
           || ...
```

`LE64(n)` 為 8 bytes 的小端序（little-endian）表示。

> ⚠️ **超重要坑**：`LE64` 的**最後一個 byte 必須 `& 0x7f`**（清除 MSB），這是 PASETO 規格要求，防止 signed / unsigned 64-bit 溢位攻擊。
>
> Flutter 團隊曾因 `paseto` 套件 v1.0.0 用 `StringBuffer + utf8.encode` 實作 LE64，導致 byte ≥ 128 時產生錯誤的多位元組 UTF-8 序列，**使 PHP 簽發的 token 無法在 Dart 驗證**。後來改成自刻 PAE / LE64 才解決——見 `wilson-e-book-english/lib/core/license/paseto_token_parser.dart:139-161`。
>
> **建議**：LE64 務必用 bytes 陣列層級操作，不要用 string / UTF-8 編碼繞圈。

LE64 pseudo code：

```
function LE64(n):
  bytes = [0; 8]
  value = n
  for i in 0..8:
    bytes[i] = value & 0xFF
    value = value >> 8
  bytes[7] = bytes[7] & 0x7F   # 清除 MSB
  return bytes
```

Dart 參考實作（`paseto_token_parser.dart:139-161`）：

```dart
static Uint8List _le64(int n) {
  final bytes = Uint8List(8);
  var value = n;
  for (var i = 0; i < 8; i++) {
    bytes[i] = value & 0xff;
    value >>= 8;
  }
  bytes[7] &= 0x7f;  // ← 關鍵
  return bytes;
}

static Uint8List _pae(List<Uint8List> pieces) {
  final out = BytesBuilder(copy: false);
  out.add(_le64(pieces.length));
  for (final piece in pieces) {
    out.add(_le64(piece.length));
    out.add(piece);
  }
  return out.toBytes();
}
```

#### Claims 清單

| 欄位 | 型別 | 必有 | 說明 |
|---|---|---|---|
| `kid` | string | ✅ | Signing key ID，例如 `signing_01JABCDE...` |
| `license_id` | int/string | ✅ | License 的內部 ID（非 `uid`） |
| `license_key_hash` | string | ✅ | License key 的 HMAC-SHA256 hex |
| `usage_fingerprint` | string | ✅ | 此 token 綁定的裝置指紋 |
| `status` | string | ✅ | License 狀態（`active` / `grace`） |
| `max_usages` | int | ✅ | 最大座位數 |
| `force_online_after` | string (ISO 8601) | ✅ | 最晚何時必須重新線上驗證 |
| `licensable_type` | string / null | ✅ | morphTo 類型（本專案目前都是 null） |
| `licensable_id` | int / null | ✅ | morphTo ID（本專案目前都是 null） |
| `license_expires_at` | string (ISO 8601) | ❌ | **僅當 License 有到期日才有** |
| `grace_until` | string (ISO 8601) | ❌ | **僅當處於寬限期才有** |
| `wrapped_content_key` | object | ✅ | ECDH 包裝後的 content key，見 [5.3](#53-ecdh--hkdf--xchacha20-內容金鑰解包) |
| `iat` | int (unix timestamp) | ✅ | 簽發時間 |
| `nbf` | int (unix timestamp) | ✅ | 最早可用時間 |
| `exp` | int (unix timestamp) | ✅ | 到期時間 |
| `sub` | string | ✅ | License ID 字串化 |
| `iss` | string | ✅ | 固定 `"wilson-ebook-admin"` |

#### Clock skew 處理建議

後端 `config/licensing.php` 設定 `clock_skew_seconds: 60`，表示允許 ±60 秒誤差。App 端驗證 `exp` / `nbf` 時建議：

```dart
const clockSkew = Duration(seconds: 60);
final now = DateTime.now().toUtc();
if (now.isAfter(exp.add(clockSkew))) throw TokenExpired();
if (now.isBefore(nbf.subtract(clockSkew))) throw TokenNotYetValid();
```

另外建議實作 **server time delta** 技巧：activate 時記下 `server_iat = claims.iat`，算出 `delta = now - server_iat`，之後判斷過期時用 `now - delta`，可防使用者調整系統時鐘繞過。Flutter 端做法見 `wilson-e-book-english/lib/core/license/server_time_validator.dart`。

### 5.3 ECDH + HKDF + XChaCha20 內容金鑰解包

Token 中的 `wrapped_content_key` claim 是個 JSON object：

```json
{
  "alg": "X25519+HKDF-SHA256+XChaCha20-Poly1305",
  "server_ephemeral_public_key": "base64(32 bytes)",
  "salt": "base64(32 bytes)",
  "nonce": "base64(24 bytes)",
  "ciphertext": "base64(48 bytes for 32-byte key + 16-byte poly1305 tag)",
  "aad": "wilson-content-key-wrap-v1"
}
```

#### 解包步驟（語言中立 pseudo code）

```
INPUT:
  wrapped          : object (wrapped_content_key claim)
  client_secret_key: 32 bytes (此次 activate 用的 X25519 private key)
  license_id       : string (PASETO claim license_id, UTF-8)
  fingerprint      : string (當前裝置指紋)

STEP 1: 驗證結構
  assert wrapped.alg == "X25519+HKDF-SHA256+XChaCha20-Poly1305"

STEP 2: Base64 decode
  server_pub   = base64_decode(wrapped.server_ephemeral_public_key)  # 32 bytes
  salt         = base64_decode(wrapped.salt)                          # 32 bytes
  nonce        = base64_decode(wrapped.nonce)                         # 24 bytes
  ct_with_tag  = base64_decode(wrapped.ciphertext)                    # ≥ 16 bytes

  assert len(server_pub) == 32
  assert len(nonce) == 24
  assert len(ct_with_tag) >= 16

STEP 3: ECDH
  shared_secret = X25519(client_secret_key, server_pub)  # 32 bytes

STEP 4: HKDF-SHA256 派生 wrap key
  info = utf8("wilson-content-key-wrap-v1")
      || byte(0x00)
      || utf8(license_id)
      || byte(0x00)
      || utf8(fingerprint)

  wrap_key = HKDF-SHA256(
    ikm    = shared_secret,
    salt   = salt,
    info   = info,
    length = 32
  )

STEP 5: 拆開 ciphertext 與 Poly1305 tag
  # ⚠️ libsodium 輸出是 ciphertext||tag 串接，tag 固定 16 bytes
  tag_len    = 16
  ciphertext = ct_with_tag[0 .. len-tag_len]
  mac        = ct_with_tag[len-tag_len .. len]

STEP 6: XChaCha20-Poly1305 解密
  content_key = XChaCha20-Poly1305-Decrypt(
    key        = wrap_key,
    nonce      = nonce,
    aad        = utf8("wilson-content-key-wrap-v1"),
    ciphertext = ciphertext,
    mac        = mac
  )

  assert len(content_key) == 32

STEP 7: 銷毀中間值
  zeroize(shared_secret)
  zeroize(wrap_key)
  zeroize(client_secret_key)

RETURN content_key
```

#### 關鍵提醒

##### 1. `info` 的組裝順序不可變

```
info = "wilson-content-key-wrap-v1" || 0x00 || license_id || 0x00 || fingerprint
```

- `license_id` 是 PASETO claim 裡的值（通常是整數 ID，要轉字串）
- `fingerprint` 必須與 activate 請求中送出的**完全相同**字串

如果 App 端 `license_id` 用了 License 的 `uid` 欄位（ULID），HKDF 會派生出不同的 wrap key，解密失敗。後端 `ContentKeyWrapper::wrap()` 用的是 `(string) $license->id`（`app/Http/Controllers/Api/LicenseController.php:97`），即**內部自增 ID**的字串形式。

##### 2. ct||tag 分離（⚠️ Dart/某些套件的坑）

libsodium 的 `crypto_aead_xchacha20poly1305_ietf_encrypt()` 輸出是**ciphertext 與 tag 串接**的一整塊 buffer。但很多語言的套件（例如 Dart `cryptography`、JS `@stablelib/xchacha20poly1305` 的 `SecretBox` 風格 API）要求**分離**：

```dart
// ❌ 錯誤：直接把整包丟進去
final box = SecretBox.fromConcatenation(ct_with_tag, nonceLength: 24, macLength: 16);
final plaintext = await Xchacha20.poly1305Aead().decrypt(box, secretKey: wrapKey, aad: aad);
// ↑ SecretBox.fromConcatenation 會把 nonce 也算進去，格式不對

// ✅ 正確：手動拆
const tagLength = 16;
final ciphertext = ct_with_tag.sublist(0, ct_with_tag.length - tagLength);
final mac = Mac(ct_with_tag.sublist(ct_with_tag.length - tagLength));
final box = SecretBox(ciphertext, nonce: nonce, mac: mac);
final plaintext = await Xchacha20.poly1305Aead().decrypt(box, secretKey: wrapKey, aad: utf8.encode(aad));
```

參考 `wilson-e-book-english/lib/core/license/content_key_wrapper.dart:163-178`。

##### 3. Ephemeral secret key 必須保留到解包完成

很多人會想在呼叫 activate 前就把 secret key 銷毀，或存進 Keychain——**都不行**。解包時需要用 secret key 做 ECDH，因此：

```
[App 產生 keypair] ──► [呼叫 activate] ──► [拿到 token] ──► [解包 content key]
      │                                                              │
      └── secret key 必須一直活在記憶體中直到這裡 ──────────────────┘
      
[解包完成] ──► [立刻 zeroize secret key]
```

Flutter 做法：`EphemeralKeyPair` 類別持有 `SimpleKeyPair`，解包完成後在 `finally` block 呼叫 `destroy()` 盡力 zeroize（Dart GC 無法 100% 保證，但至少 best-effort）。

##### 4. 套件選擇（Dart）

| 需求 | 推薦套件 | 備註 |
|---|---|---|
| X25519、HKDF-SHA256、XChaCha20-Poly1305、Ed25519 | `cryptography: ^2.9.0` | 純 Dart，無 FFI 依賴 |
| HTTP | `http: ^1.4.0` | 標準套件 |
| Secure storage | `flutter_secure_storage: ^10.0.0` | Android Keystore / iOS Keychain |
| ❌ 不建議 | `paseto` 套件 | v1.0.0 有 LE64 編碼 bug，跨平台會失敗 |

### 5.4 Content key 銷毀與重用

#### 儲存策略

解包得到 32-byte content key 後，**不建議每次重啟 App 都重新 activate**（會浪費網路、消耗 seat quota、也無法離線使用）。正確做法：

1. 解出 content key bytes
2. `base64_encode(content_key_bytes)`
3. 存進 secure storage（Android Keystore / iOS Keychain）
4. 同時存入：`license_key`, `token`, `signing_public_key`, `license_name`, `lease_info`
5. 立即 zeroize 原 bytes buffer

#### 重啟時

```
[App 啟動]
   │
   ▼
[從 secure storage 讀取所有欄位]
   │
   ▼
[base64_decode(content_key) → 32 bytes]
   │
   ▼
[檢查 token 是否過期]
   ├─ 未過期 → 直接使用，進入主流程
   └─ 已過期 → 重新 activate
```

#### Token 過期判斷

```dart
bool isTokenExpired({
  required DateTime? tokenExpiresAt,
  required int serverTimeDeltaMs,
}) {
  if (tokenExpiresAt == null) return false;  // 永久授權
  final correctedNow = DateTime.now()
      .subtract(Duration(milliseconds: serverTimeDeltaMs));
  return correctedNow.isAfter(tokenExpiresAt);
}
```

`serverTimeDelta` 在 activate 當下計算：`delta = now - server_iat`。儲存此值後，之後都用 `now - delta` 作為「校正後的當前時間」，可以對抗使用者手動調整系統時鐘。

完整實作：`wilson-e-book-english/lib/core/license/server_time_validator.dart`、`lease_manager.dart`、`domain/lease_info.dart`。

---

## 6. 錯誤處理策略建議

### 6.1 錯誤分類

API 呼叫可能遇到三類錯誤，App 端需要分開處理：

| 類別 | 舉例 | 偵測方式 | 處理 |
|---|---|---|---|
| **網路層錯誤** | timeout、DNS fail、連不上、SSL 錯誤 | HTTP client 拋例外（`SocketException`、`TimeoutException`） | 顯示「網路連線失敗」，可重試 |
| **HTTP/Laravel 錯誤** | 404 route not found、500 伺服器錯誤 | HTTP status ≠ 200 但 body 不是 `{success: false, error: {...}}` 結構 | 顯示「伺服器暫時無法使用」 |
| **API 業務錯誤** | `INVALID_KEY`、`USAGE_LIMIT_REACHED`、`EXPIRED_LICENSE` | body 符合 `{success: false, error: {code, message}}` | 依 `code` 給對應訊息 |

### 6.2 建議的 Exception 結構

Flutter 端範例（`license_service.dart:15-40`）：

```dart
class LicenseApiException implements Exception {
  const LicenseApiException(this.statusCode, this.message, {this.errorCode});

  final int statusCode;
  final String message;
  final String? errorCode;   // 例：'INVALID_KEY'、'USAGE_LIMIT_REACHED'

  // 便利 getter
  bool get isInvalidKey => statusCode == 404;
  bool get isUsageLimitReached => statusCode == 409;
  bool get isRevoked => statusCode == 403;
  bool get isExpired => statusCode == 410;
  bool get isSuspended => statusCode == 423;
}
```

### 6.3 錯誤碼 → 使用者訊息對應表

以下是建議的中文訊息，實際專案可依 UI 文案調整：

| `errorCode` | 使用者可見訊息 | 是否可重試 | 建議動作 |
|---|---|---|---|
| `VALIDATION_FAILED` | 「系統錯誤」（不是使用者錯） | ❌ | 記 log，通知開發團隊 |
| `INVALID_EPHEMERAL_KEY` | 「系統錯誤」 | ❌ | 記 log，可能是 App 端密碼學套件 bug |
| `INVALID_KEY` | 「授權碼無效，請確認輸入」 | ❌（除非使用者改輸入） | 讓使用者重新輸入 |
| `SUSPENDED_LICENSE` | 「此授權已被停用，請聯絡客服」 | ❌ | 強制登出，不允許重試 |
| `EXPIRED_LICENSE` | 「授權已過期，請續約」 | ❌ | 強制登出，引導續約 |
| `LICENSE_NOT_ACTIVE` | 「授權尚未啟用或狀態異常」 | ❌ | 顯示並聯絡客服 |
| `USAGE_LIMIT_REACHED` | 「此授權已綁定其他裝置」 | ❌ | 提示使用者先在舊裝置解除綁定（目前需聯絡客服處理） |
| `FINGERPRINT_CONFLICT` | 「此裝置已綁定其他授權」 | ❌ | 罕見，聯絡客服 |
| `FINGERPRINT_MISMATCH` | 「需要重新啟用授權」 | ✅ | 清除本地 token，讓使用者重新呼叫 activate |
| `SERVER_ERROR` | 「伺服器暫時無法使用，請稍後再試」 | ✅（最多 1-2 次，間隔退避） | 後端統一遮蔽的 5xx；若連續失敗請同時提供發生時間給後端 log 查詢 |

Flutter 實際做法（`wilson-e-book-english/lib/features/auth/presentation/providers/auth_provider.dart:213-226`）：

```dart
on LicenseApiException catch (e) {
  final message = switch (e.errorCode) {
    'INVALID_KEY' => '授權碼無效',
    'EXPIRED_LICENSE' => '授權碼已過期',
    'USAGE_LIMIT_REACHED' => '此授權碼已綁定其他裝置',
    'SUSPENDED_LICENSE' => '此授權碼已被停用',
    'LICENSE_NOT_ACTIVE' => '此授權碼尚未啟用',
    _ => '認證失敗，請稍後再試',
  };
  state = AuthState(status: AuthStatus.error, errorMessage: message);
}
```

### 6.4 重試策略

| 情境 | 重試策略 |
|---|---|
| 網路層錯誤（timeout、連線失敗） | 指數退避：1s → 2s → 4s → 放棄；每次重試顯示「重新連線中…」 |
| `SERVER_ERROR` (500) | 指數退避重試 1-2 次（建議帶**新的 ephemeral keypair**，因為可能是 ECDH 階段失敗）；仍失敗則顯示通用錯誤並請使用者稍後再試 |
| `FINGERPRINT_MISMATCH`（validate） | 清除本地 token，引導使用者重新呼叫 activate（不是自動重試） |
| 其他業務錯誤（4xx） | **不要重試**，重試只會得到相同結果 |

> 📝 **提醒**：因為 500 已被統一遮蔽成 `SERVER_ERROR`，App 端無法從 response 區分是「後端設定問題（不可重試）」還是「暫時性錯誤（可重試）」。建議統一走「指數退避重試 1-2 次」的保守策略，超過上限後顯示通用錯誤訊息並請使用者稍後再試或聯絡客服。若使用者能提供發生時間，後端可透過 `api` log channel 反查 `internal_code` 得知實際原因。

### 6.5 不要做的事

- ❌ **不要**用 `try { ... } catch(_) { }` 吞掉錯誤，使用者會看不到授權失敗的原因
- ❌ **不要**把後端 `error.message` 直接顯示給使用者（是英文 debug 訊息，UX 差）
- ❌ **不要**對 `USAGE_LIMIT_REACHED` 自動重試，會浪費 rate limit
- ❌ **不要**在未連網時嘗試呼叫 activate（應改用本地 token 走 offline 驗證）
- ❌ **不要**持久化 PASETO token 的明文到非 secure 的 storage（例如 SharedPreferences / UserDefaults 的普通區），要用 Keystore / Keychain

---

## 7. App 端完整串接流程（Flutter 參考）

本節整理 Flutter 端 `AuthNotifier.submitCode` 的完整流程，作為其他平台實作的參考藍本。原始碼在 `wilson-e-book-english/lib/features/auth/presentation/providers/auth_provider.dart`。

### 7.1 首次啟用流程

```
[使用者在輸入框輸入 license key（可能帶 dash）]
         │
         ▼
[App 層取得 normalized key = input.replaceAll('-', '')]
         │
         ▼
┌───────────────────────────────────┐
│  並行執行（Future.wait）          │
│  ① getFingerprint()               │
│  ② EphemeralKeyPair.generate()    │
└───────────────┬───────────────────┘
                ▼
[呼叫 LicenseService.activate(
   licenseKey: normalized,
   fingerprint: fp,
   clientEphemeralPublicKey: ephemeralKp.publicKeyBase64,
 )]
                │
      ┌─────────┴──────────┐
      ▼                    ▼
[失敗：API/網路錯誤]    [成功：LicenseActivationResult]
      │                    │
      ▼                    ▼
[顯示錯誤，        [取 result.publicKeyBundle.signing.publicKey]
 destroy ephemeralKp]         │
                              ▼
               [PasetoTokenParser.verifyAndParse(
                  token: result.token,
                  fingerprint: fp,
                )]
                              │
                    ┌─────────┴──────────┐
                    ▼                    ▼
              [簽章/claims 失敗]    [拿到 claims]
                    │                    │
                    ▼                    ▼
              [顯示錯誤,    [ContentKeyWrapper.unwrap(
               destroy]       clientKeyPair: ephemeralKp,
                              wrappedClaim: claims.wrappedContentKey,
                              licenseId: claims.licenseId.toString(),
                              fingerprint: fp,
                            )]
                                         │
                                ┌────────┴─────────┐
                                ▼                  ▼
                          [解包失敗]        [32-byte contentKey]
                                │                  │
                                ▼                  ▼
                          [顯示錯誤,  [base64.encode(contentKeyBytes)]
                           destroy]         │
                                            ▼
                                [儲存到 secure storage:
                                  license_key,
                                  content_key (base64),
                                  token,
                                  signing_public_key,
                                  license_name,
                                  lease_info,
                                  server_time_delta]
                                            │
                                            ▼
                                [LeaseManager.scheduleExpiry(
                                  expiresAt: result.tokenExpiresAt,
                                )]
                                            │
                                            ▼
                                [state = AuthState(authenticated)]
                                            │
                                            ▼
                                [finally: ephemeralKp.destroy()]
```

#### 程式碼對照

完整流程位於 `auth_provider.dart` 的 `submitCode` 方法（lines 106-246）：

```dart
Future<void> submitCode(String code) async {
  state = const AuthState(status: AuthStatus.authenticating);

  final normalizedKey = code; // replaceAll 在 LicenseService 內部處理
  EphemeralKeyPair? ephemeralKp;

  try {
    // 1. 並行取 fingerprint + 產生 keypair
    final results = await Future.wait([
      ref.read(deviceIdProvider.future),
      EphemeralKeyPair.generate(),
    ]);
    final fingerprint = results[0] as String;
    ephemeralKp = results[1] as EphemeralKeyPair;

    // 2. 呼叫 activate
    final result = await _licenseService.activate(
      licenseKey: normalizedKey,
      fingerprint: fingerprint,
      clientEphemeralPublicKey: ephemeralKp.publicKeyBase64,
    );

    // 3. PASETO 驗證
    final signingPublicKey = result.publicKeyBundle?.signing.publicKey;
    if (signingPublicKey == null) {
      throw const PasetoVerificationException('Missing signing public key');
    }
    final parser = PasetoTokenParser(publicKeyBase64: signingPublicKey);
    final claims = await parser.verifyAndParse(
      result.token,
      fingerprint: fingerprint,
    );

    // 4. ECDH 解包
    final contentKeyBytes = await ContentKeyWrapper.unwrap(
      clientKeyPair: ephemeralKp,
      wrappedClaim: claims.wrappedContentKey,
      licenseId: claims.licenseId.toString(),
      fingerprint: fingerprint,
    );

    // 5. 建憑證並儲存
    final credentials = LicenseCredentials(
      licenseKey: normalizedKey,
      contentKey: base64.encode(contentKeyBytes),
      token: result.token,
      lease: LeaseInfo(
        token: result.token,
        expiresAt: result.tokenExpiresAt,
      ),
      serverTimeDelta: ServerTimeValidator.calculateDelta(claims.iat),
      signingPublicKey: signingPublicKey,
      licenseName: result.license.name,
    );
    await _licenseRepository.saveCredentials(credentials);

    // 6. 排程 lease 到期
    _setupLeaseManager(credentials.lease, serverTimeDelta: credentials.serverTimeDelta);

    // 7. 更新 state
    state = AuthState(
      status: AuthStatus.authenticated,
      lease: credentials.lease,
      licenseName: credentials.licenseName,
    );
  } on LicenseApiException catch (e) {
    state = AuthState(
      status: AuthStatus.error,
      errorMessage: _messageForErrorCode(e.errorCode),
    );
  } on PasetoVerificationException catch (_) {
    state = const AuthState(
      status: AuthStatus.error,
      errorMessage: '授權驗證失敗',
    );
  } catch (e) {
    state = AuthState(
      status: AuthStatus.error,
      errorMessage: '連線失敗，請確認網路狀態',
    );
  } finally {
    ephemeralKp?.destroy();  // ⚠️ 無論成功失敗都要銷毀
  }
}
```

### 7.2 週期驗證（heartbeat）

建議觸發時機：

1. App 從背景回到前景
2. 每天定時一次（若 App 長時間在前景）
3. 使用者打開特定功能頁（例如下載新內容前）

```dart
Future<void> sendHeartbeat() async {
  final credentials = await _licenseRepository.loadCredentials();
  if (credentials == null) return;

  try {
    await _licenseService.validate(
      licenseKey: credentials.licenseKey,
      fingerprint: await _fingerprint,
      data: {
        'app_version': await _appVersion,
        'last_active': DateTime.now().toIso8601String(),
      },
    );
  } on LicenseApiException catch (e) {
    // FINGERPRINT_MISMATCH / EXPIRED_LICENSE / SUSPENDED_LICENSE
    // → 清除本地憑證並強制重新 activate
    if (e.statusCode == 403 || e.statusCode == 410 || e.statusCode == 423) {
      await _licenseRepository.clear();
      state = const AuthState(status: AuthStatus.leaseExpired);
    }
    // 其他錯誤（網路、500）靜默忽略，下次再試
  }
}
```

### 7.3 Token 過期換發

當 `correctedNow > tokenExpiresAt` 時需重新 activate：

```
[定時檢查 / LeaseManager 到期 callback]
       │
       ▼
[清除記憶體中的 token (但保留 content_key)]
       │
       ▼
[彈出「重新驗證」UI]
       │
       ▼
[使用者可選擇「立即重新連線」或「稍後」]
       │
       ├─ 立即：重新呼叫 activate（需新 ephemeral keypair）
       │   ├─ 成功：更新 token、wrapped_content_key、lease；
       │   │       content_key 不變（仍是原本的 32 bytes）
       │   └─ 失敗：顯示錯誤
       │
       └─ 稍後：封鎖功能，強制使用者處理
```

**關鍵洞察**：重新 activate 會得到**新**的 `wrapped_content_key`，但解出來的 content key 是**同一把**（因為 CEK 綁在 LicenseScope 上，不會因單一裝置重新驗證而變更）。App 端可以：

- 解出來後比對是否與舊的相同（sanity check）
- 直接用新解出的替換舊的
- 或者偷懶一點直接用舊的（因為理論上一定相同）

### 7.4 使用者重新認證（「登出」）

**目前版本後端不支援 deactivate API**，因此 App 端「登出」的做法是：

```dart
Future<void> logout() async {
  await _licenseRepository.clear();  // 清 secure storage
  state = const AuthState(status: AuthStatus.unauthenticated);
  // 不呼叫後端任何 API
}
```

#### 注意

- 這不會釋放 `LicenseUsage` 的座位：後端資料庫裡這筆 usage 仍是 `active`。
- 如果使用者換了裝置，舊裝置的座位不會自動釋出，新裝置呼叫 activate 可能會拿到 `USAGE_LIMIT_REACHED`。
- **目前處理方式**：使用者必須聯絡客服，由後台管理員手動 revoke 舊 usage 或調整 `max_usages`。
- **未來規劃**：若要支援 App 端自助解綁，後端需新增 `POST /api/licensing/v1/deactivate`（目前 Flutter `license_service.dart:87-96` 已有呼叫碼，但後端路由未註冊，呼叫會拿到 Laravel 預設 404）。

### 7.5 時鐘篡改偵測（Anti-tamper）

使用者若調整系統時鐘，可能讓 `token_expires_at > now` 永遠成立。防禦做法：

1. **記錄 serverTimeDelta**：activate 當下計算 `delta = local_now - server_iat_claim`
2. **存到 secure storage**：與其他 credential 一起
3. **所有時間比較都用 `correctedNow = local_now - delta`**
4. **背景偵測**：App 從背景回前景時，比較 `delta` 是否突然變化超過閾值（Flutter 用 30 分鐘），變化則視為篡改，強制重新驗證

Flutter 實作：`wilson-e-book-english/lib/core/license/server_time_validator.dart`

---

## 8. 附錄

### 8.1 完整 curl 範例集

#### A. Health check

```bash
curl -s https://license.wilson-ebook.com/api/licensing/v1/health | jq
```

#### B. 首次 activate（最小 payload）

> 注意：`client_ephemeral_public_key` 需是真實的 base64 encoded X25519 public key（32 bytes）。以下範例無法重現，因為對應的 secret key 並未給出。實際測試請用下方 bash 腳本生成。

```bash
curl -X POST https://license.wilson-ebook.com/api/licensing/v1/activate \
  -H "Content-Type: application/json; charset=utf-8" \
  -d '{
    "license_key": "A7KMR3NPX9BWD5HTJ2QF",
    "fingerprint": "test-device-001",
    "client_ephemeral_public_key": "hBM1p5VOlI79cGCX3Z2FfVe6q7y0Q2RtIxdCC5TnYAE="
  }'
```

#### C. Activate 帶 metadata

```bash
curl -X POST https://license.wilson-ebook.com/api/licensing/v1/activate \
  -H "Content-Type: application/json; charset=utf-8" \
  -d '{
    "license_key": "A7KMR3NPX9BWD5HTJ2QF",
    "fingerprint": "test-device-001",
    "client_ephemeral_public_key": "hBM1p5VOlI79cGCX3Z2FfVe6q7y0Q2RtIxdCC5TnYAE=",
    "metadata": {
      "os": "iOS",
      "os_version": "17.4",
      "app_version": "1.2.3",
      "device_model": "iPhone15,2"
    }
  }'
```

#### D. Validate（heartbeat，不帶 data）

```bash
curl -X POST https://license.wilson-ebook.com/api/licensing/v1/validate \
  -H "Content-Type: application/json; charset=utf-8" \
  -d '{
    "license_key": "A7KMR3NPX9BWD5HTJ2QF",
    "fingerprint": "test-device-001"
  }'
```

#### E. Validate 帶 data（回報閱讀進度）

```bash
curl -X POST https://license.wilson-ebook.com/api/licensing/v1/validate \
  -H "Content-Type: application/json; charset=utf-8" \
  -d '{
    "license_key": "A7KMR3NPX9BWD5HTJ2QF",
    "fingerprint": "test-device-001",
    "data": {
      "last_chapter": "unit-7",
      "reading_progress_pct": 42,
      "timestamp": "2026-04-10T08:15:30+08:00"
    }
  }'
```

#### F. 觸發錯誤：送帶 dash 的 license key（會拿 404 INVALID_KEY）

```bash
# ❌ 錯誤範例：dash 沒移除
curl -X POST https://license.wilson-ebook.com/api/licensing/v1/activate \
  -H "Content-Type: application/json; charset=utf-8" \
  -d '{
    "license_key": "A7KMR-3NPX9-BWD5H-TJ2QF",
    "fingerprint": "test-device-001",
    "client_ephemeral_public_key": "hBM1p5VOlI79cGCX3Z2FfVe6q7y0Q2RtIxdCC5TnYAE="
  }'
# 預期回應：{"success":false,"error":{"code":"INVALID_KEY","message":"License key is invalid or not found"}}
```

#### G. 觸發錯誤：ephemeral key 長度錯誤（會拿 400 INVALID_EPHEMERAL_KEY）

```bash
curl -X POST https://license.wilson-ebook.com/api/licensing/v1/activate \
  -H "Content-Type: application/json; charset=utf-8" \
  -d '{
    "license_key": "A7KMR3NPX9BWD5HTJ2QF",
    "fingerprint": "test-device-001",
    "client_ephemeral_public_key": "dG9vIHNob3J0"
  }'
# 預期回應：{"success":false,"error":{"code":"INVALID_EPHEMERAL_KEY","message":"client_ephemeral_public_key must be base64 of a 32-byte X25519 public key"}}
```

#### H. 用 PHP 產生測試用 ephemeral keypair

```php
<?php
// 產生一對 X25519 keypair，列印出 base64(public) 供 curl 測試
$kp = sodium_crypto_box_keypair();
$pub = sodium_crypto_box_publickey($kp);
$priv = sodium_crypto_box_secretkey($kp);

echo "Public (base64): " . base64_encode($pub) . "\n";
echo "Secret (base64): " . base64_encode($priv) . "\n";
// 注意：secret 必須保留才能在拿到回應後解包 wrapped_content_key
```

執行：`vendor/bin/sail php -r 'require "keygen.php";'` 或 tinker。

#### I. 用 Dart 產生測試用 ephemeral keypair

```dart
// lib/scripts/gen_keypair.dart
import 'dart:convert';
import 'package:cryptography/cryptography.dart';

Future<void> main() async {
  final kp = await X25519().newKeyPair();
  final pub = await kp.extractPublicKey();
  final priv = await kp.extractPrivateKeyBytes();

  print('Public (base64): ${base64.encode(pub.bytes)}');
  print('Secret (base64): ${base64.encode(priv)}');
}
```

執行：`dart run lib/scripts/gen_keypair.dart`

### 8.2 Response 欄位字典

#### `license` 物件

| 欄位 | 型別 | 說明 |
|---|---|---|
| `id` | string (ULID) | License 的對外公開 ID |
| `name` | string | 後台設定的授權名稱（專案擴充欄位） |
| `status` | string | `pending` / `active` / `grace` / `expired` / `suspended` / `cancelled` |
| `activated_at` | string (ISO 8601) / null | 首次啟用時間 |
| `expires_at` | string (ISO 8601) / null | 到期時間（`null` = 永久） |
| `max_usages` | int | 最大同時啟用座位數 |
| `active_usages` | int | 當前已啟用座位數（僅 activate response 有） |
| `available_seats` | int | 剩餘座位 = `max_usages - active_usages`（僅 activate response 有） |
| `features` | array | License 對應的 feature flags（本專案目前為空陣列） |
| `entitlements` | array | License 對應的權限集（本專案目前為空陣列） |
| `grace_days_remaining` | int | 寬限期剩餘天數（**僅在 grace 狀態才有**） |

#### `usage` 物件

| 欄位 | 型別 | 說明 |
|---|---|---|
| `id` | int | Usage 的內部 ID |
| `fingerprint` | string | 裝置指紋（回傳的就是送出的） |
| `status` | string | `active` / `revoked` |
| `registered_at` | string (ISO 8601) | 首次註冊時間 |
| `last_seen_at` | string (ISO 8601) | 最後心跳時間 |

#### `public_key_bundle` 物件（**只在 activate response 有**）

| 欄位 | 型別 | 說明 |
|---|---|---|
| `signing` | object | 當前 signing key 資訊 |
| `signing.kid` | string | Signing key ID |
| `signing.public_key` | string (base64) | Ed25519 公鑰（32 bytes） |
| `signing.certificate` | string (base64) | Signing key 的憑證（由 root key 簽發） |
| `signing.valid_from` | string (ISO 8601) | 生效時間 |
| `signing.valid_until` | string (ISO 8601) | 失效時間 |
| `root` | object | Root key 資訊 |
| `root.kid` | string | Root key ID |
| `root.public_key` | string (base64) | Ed25519 公鑰（32 bytes） |
| `root.valid_from` | string (ISO 8601) | 生效時間 |
| `root.valid_until` | string (ISO 8601) / null | 失效時間（通常為 null） |
| `issued_at` | string (ISO 8601) | bundle 產生時間 |

#### Activate response 頂層欄位

| 欄位 | 型別 | 說明 |
|---|---|---|
| `license` | object | 見上 |
| `usage` | object | 見上 |
| `token` | string | PASETO v4.public token |
| `token_expires_at` | string (ISO 8601) | Token 過期時間（最長 = License 到期日，或 36500 天若永久） |
| `refresh_after` | string (ISO 8601) | 建議在此時間之後換發（= `token_expires_at - 24h`） |
| `force_online_after` | string (ISO 8601) | 最晚何時必須重新線上驗證（本專案設為 9999 年底，實質無限） |
| `public_key_bundle` | object | 見上 |

### 8.3 PASETO Claim 字典

| Claim | 型別 | 來源 | 說明 |
|---|---|---|---|
| `kid` | string | `LicensingKey.kid` | 此 token 使用的 signing key ID |
| `license_id` | int | `License.id`（內部自增 ID） | **⚠️ 不是** `uid`；解包時作為 HKDF info 的一部分 |
| `license_key_hash` | string | `License.key_hash` | HMAC-SHA256(license_key, salt) 的 hex |
| `usage_fingerprint` | string | `LicenseUsage.usage_fingerprint` | 此 token 綁定的裝置指紋 |
| `status` | string | `License.status.value` | `active` 或 `grace` |
| `max_usages` | int | `License.max_usages` | 最大座位 |
| `force_online_after` | string | 計算自 `License.getForceOnlineAfterDays()` | 最晚必須上線時間 |
| `licensable_type` | string / null | `License.licensable_type` | morphTo 類型（目前皆 null） |
| `licensable_id` | int / null | `License.licensable_id` | morphTo ID（目前皆 null） |
| `license_expires_at` | string / — | `License.expires_at` 存在時才有 | License 到期日 |
| `grace_until` | string / — | 處於寬限期才有 | 寬限期結束時間 |
| `wrapped_content_key` | object | 本專案擴充的 `extra_claim` | ECDH wrapping 結果 |
| `iat` | int (unix) | `now()` | 簽發時間 |
| `nbf` | int (unix) | `now()` | 最早可用時間 |
| `exp` | int (unix) | `iat + ttl_days` | 過期時間 |
| `sub` | string | `(string) $license->id` | License ID 字串化 |
| `iss` | string | `config('licensing.offline_token.issuer')` | 固定 `wilson-ebook-admin` |

### 8.4 Dart / Flutter 推薦套件清單

```yaml
dependencies:
  flutter:
    sdk: flutter

  # 網路
  http: ^1.4.0

  # 密碼學（純 Dart，無 FFI）
  cryptography: ^2.9.0

  # 安全儲存
  flutter_secure_storage: ^10.0.0

  # 狀態管理（非必需，看專案喜好）
  flutter_riverpod: ^3.2.0

  # 裝置資訊（用於 fingerprint）
  # iOS: 直接用 platform channel 取 identifierForVendor
  # Android: 自己寫 plugin 取 MAC address（見 wilson-e-book-english）

# ❌ 不要加這個：
#   paseto: ^1.0.0   # v1.0.0 的 PAE LE64 編碼有 bug，無法驗證 PHP 簽發的 token
```

### 8.5 各平台 Fingerprint 實作範例

#### iOS (Swift)

```swift
import UIKit

func getFingerprint() -> String {
    return UIDevice.current.identifierForVendor?.uuidString ?? "unknown"
}
```

#### Android (Kotlin) — 參考 `wilson-e-book-english` 的做法

```kotlin
// MAC address via NetworkInterface（Android 10+ 可用）
fun getFingerprint(): String {
    try {
        val interfaces = NetworkInterface.getNetworkInterfaces() ?: return "unknown"
        for (ni in interfaces) {
            if (ni.isLoopback) continue
            val mac = ni.hardwareAddress ?: continue
            if (mac.isEmpty()) continue
            val formatted = mac.joinToString(":") { "%02X".format(it) }
            // 過濾假 MAC
            if (formatted !in setOf("00:00:00:00:00:00", "02:00:00:00:00:00")) {
                return formatted
            }
        }
    } catch (e: Exception) {
        // fallback
    }
    return "unknown"
}
```

#### macOS (Shell)

```bash
ioreg -d2 -c IOPlatformExpertDevice | awk -F\" '/IOPlatformUUID/ { print $(NF-1) }'
```

#### Windows (PowerShell)

```powershell
(Get-ItemProperty 'HKLM:\SOFTWARE\Microsoft\Cryptography').MachineGuid
```

#### Linux (Shell)

```bash
cat /etc/machine-id || cat /var/lib/dbus/machine-id
```

### 8.6 FAQ

#### Q1：為什麼 license_key 送出前要移除 dash？

因為後端 `License::hashKey()` 對**原始字串**做 HMAC-SHA256，沒有 normalize。`LicenseKeyGenerator::format()` 只是顯示用的方法，資料庫 `key_hash` 存的是**去 dash 版本**的 HMAC。如果送帶 dash 的字串，hash 對不上，會拿到 `INVALID_KEY` 404。

#### Q2：為什麼要自己實作 PASETO 驗證而不用 `paseto` 套件？

`paseto` 套件 v1.0.0 的 PAE (Pre-Authentication Encoding) 實作用 `StringBuffer + utf8.encode` 編碼 LE64，導致 byte ≥ 128 時產生錯誤的多位元組 UTF-8 序列，**PHP 簽發的 token 無法在 Dart 驗證**。解法是手刻 PAE / LE64，用 bytes 陣列層級操作並 `& 0x7f` 清 MSB。詳見 `paseto_token_parser.dart` 頂部註解。

若未來該套件修復此問題，可評估是否切換。

#### Q3：為什麼 Dart `cryptography` 套件解 XChaCha20-Poly1305 時要把 ciphertext 和 tag 分開？

libsodium 的 AEAD API 輸出是 `ciphertext || tag` 一整塊串接的 buffer。但 Dart `cryptography` 套件的 `SecretBox` 是 `SecretBox(ciphertext, nonce, mac)` 結構，**要求分離**。因此 App 端必須把 libsodium 輸出的最後 16 bytes 切下來當 MAC，剩下的當 ciphertext：

```dart
const tagLength = 16;
final ciphertext = ctWithTag.sublist(0, ctWithTag.length - tagLength);
final mac = Mac(ctWithTag.sublist(ctWithTag.length - tagLength));
```

#### Q4：永久授權和一般授權有什麼差異？

| 特性 | 有到期日 | 永久授權 |
|---|---|---|
| `license.expires_at` | ISO 8601 時間 | `null` |
| 會進入 `grace` / `expired` 狀態 | ✅ | ❌ 永遠 `active` |
| Token TTL | `min(剩餘天數, ∞)` | 36500 天 |
| Token 含 `license_expires_at` claim | ✅ | ❌ |
| Token 含 `grace_until` claim | 寬限期內才有 | ❌ |
| App 端需要 refresh token | 到期前需要 | 實質不需要 |

#### Q5：使用者換裝置了怎麼辦？

目前後端不提供 App 端自助解綁 API。使用者在新裝置呼叫 activate 時：

- 若 `active_usages < max_usages` → 成功註冊新 usage
- 若已達 `max_usages` → 收到 `USAGE_LIMIT_REACHED` (409)

此時使用者必須聯絡客服，由後台管理員在 Filament 後台 revoke 舊 usage 或調整 `max_usages`。

**未來規劃**：若要支援 App 端自助，後端需新增 `POST /api/licensing/v1/deactivate` endpoint。目前 Flutter 端 `license_service.dart:87-96` 已有呼叫碼，但**後端路由未註冊**，呼叫會拿到 Laravel 預設 404。

#### Q6：Content key 什麼時候會改變？

`ContentEncryptionKey` 綁在 `LicenseScope` 上（`license_scopes.content_encryption_key_id`），當：

- 同一 scope 下的不同 License → 共用同一把 content key
- 同一 License 下的不同 Usage → 共用同一把 content key
- 同一 Usage 重新 activate → 解出的 content key **完全相同**（雖然每次的 `wrapped_content_key` 都不一樣，因為 server ephemeral key 每次都新）

唯一會改變的情境：後台管理員手動切換 LicenseScope 的 `content_encryption_key_id`（例如金鑰輪換）。發生時 App 端舊的 content key 會失效，需要重新 activate。

#### Q7：token 可以傳給另一台裝置用嗎？

**不行**。Token 的 claim 包含 `usage_fingerprint`，App 端驗證時會比對當前裝置指紋是否相符，不符合就拒絕。即使跳過這個檢查，`wrapped_content_key` 的 HKDF `info` 也繫結了 fingerprint，不同裝置解出的 `wrap_key` 不同，解密會失敗（Poly1305 tag 驗證不通過）。

#### Q8：Fingerprint 可以換嗎？

不建議。`fingerprint` 變更後，`validate` 會拿到 `FINGERPRINT_MISMATCH`，且 `activate` 會當作新裝置消耗一個 seat。建議 fingerprint 取自**最穩定**的裝置屬性（同一台裝置每次呼叫都得到相同值）。

#### Q9：為什麼要把 ephemeral secret key 立刻銷毀？

**Forward secrecy**：就算未來某一天 content key 外流 / wrap key 被破解，攻擊者也無法回推中間值去解開過去交付過的其他 wrapped content key，因為每次 activate 用的 ephemeral keypair 都已銷毀。

#### Q10：如何測試後端部署是否正確？

1. `GET /health` 回 `"status": "healthy"`
2. 後台建一張測試 License（狀態 `pending`）
3. 用 curl 呼叫 activate，應收到 200 + token
4. 再呼叫 validate（相同 fingerprint），應收到 200
5. 用不同 fingerprint 呼叫 validate，應收到 `FINGERPRINT_MISMATCH` 403
6. 把後台 License 改成 `suspended`，再呼叫 validate，應收到 `SUSPENDED_LICENSE` 423

#### Q11：為什麼 500 錯誤都只看到 `SERVER_ERROR`，看不到具體原因？

這是**刻意的資安設計**。詳細原因會暴露後端設定細節（例如 `MISSING_CONTENT_KEY` 暗示 LicenseScope 沒綁 CEK、`TOKEN_REQUIRED` 暗示 config 被改過）、檔案路徑、SQL 或 stack trace，這些資訊對攻擊者有價值，對 App 端除錯幫助卻很有限。

後端採用三層保護：

1. **Controller 層**：`LicenseController::serverError()` helper 把 4 個業務面 5xx（`TOKEN_REQUIRED`、`MISSING_CONTENT_KEY`、`WRAP_FAILED`、`TOKEN_ISSUE_FAILED`）統一遮蔽
2. **全域 exception handler**：`bootstrap/app.php` 的 `withExceptions` render callback 攔截所有 `api/licensing/v1/*` 下未捕獲的 Throwable，同樣遮蔽
3. **Log 層**：兩層都會把完整 `internal_code`、`exception_class`、`exception_message`、`exception_trace` 記錄到 `api` channel (`storage/logs/api-YYYY-MM-DD.log`)，保留 30 天

**App 端遇到 `SERVER_ERROR` 時的正確做法**：
- 顯示「伺服器暫時無法使用，請稍後再試」
- 內部紀錄發生時間、license_key（後幾碼）、fingerprint
- 使用者回報時提供這些資訊給後端團隊，後端可透過 log 反查 `internal_code` 得知實際原因

**不要做**：
- ❌ 對 `SERVER_ERROR` 做 code 分流處理（永遠只會拿到同一個 code）
- ❌ 連續無限重試（rate limit 會觸發，且很可能是設定問題無法自動恢復）
- ❌ 把 `SERVER_ERROR` 回應內容直接顯示給使用者（`"Server error"` 不是好的 UX 文案）

---

## 參考資料

### 後端原始碼

- `routes/api.php` — 路由定義
- `app/Http/Controllers/Api/LicenseController.php` — Activate with ECDH
- `app/Http/Controllers/Api/ValidateController.php` — Validate with heartbeat
- `app/Services/ContentKeyWrapper.php` — ECDH + HKDF + XChaCha20 包裝
- `app/Services/WilsonPasetoTokenService.php` — PASETO 簽發（支援 `extra_claims`）
- `app/Services/LicenseKeyGenerator.php` — 授權碼產生與格式化
- `config/licensing.php` — 授權系統設定（TTL、rate limit、policies）
- `tests/Feature/Api/LicenseActivateEcdhTest.php` — ECDH 錯誤路徑測試

### Flutter 參考實作（`wilson-e-book-english`）

- `lib/core/license/license_service.dart` — HTTP 呼叫層
- `lib/core/license/content_key_wrapper.dart` — ECDH 解包
- `lib/core/license/paseto_token_parser.dart` — 自刻 PASETO 驗證
- `lib/core/license/license_repository.dart` — Secure storage
- `lib/core/license/server_time_validator.dart` — 時鐘篡改偵測
- `lib/core/license/lease_manager.dart` — Lease 到期排程
- `lib/features/auth/presentation/providers/auth_provider.dart` — 完整 activate 流程
- `android/app/src/main/kotlin/com/wilson/wilson_ebook/DeviceFingerprintPlugin.kt` — MAC fingerprint

### 外部規格

- [PASETO v4.public 規格](https://github.com/paseto-standard/paseto-spec/blob/master/docs/01-Protocol-Versions/Version4.md)
- [RFC 5869 — HKDF](https://datatracker.ietf.org/doc/html/rfc5869)
- [RFC 7539 — ChaCha20-Poly1305](https://datatracker.ietf.org/doc/html/rfc7539)（XChaCha20 是擴展版 nonce 至 24 bytes）
- [RFC 7748 — X25519](https://datatracker.ietf.org/doc/html/rfc7748)
- [masterix21/laravel-licensing](https://github.com/masterix21/laravel-licensing)




