# 電子書 App 授權使用場景設計

## Context

設計 Flutter APK 電子書 App 的完整授權流程：使用者首次使用必須連網輸入 20 字元授權碼（英文大寫 + 數字），驗證成功後可完全離線使用直到到期，到期後需輸入新授權碼。授權碼驗證後才能解密 App 內已加密的素材。僅限 1 台裝置使用。

素材解密為即時動態解密：小檔（文字、圖片）解密到記憶體使用，大檔（影片）解密為暫存檔或即時串流解密。

---

## 完整使用流程圖

```
使用者取得 APK（內含 AES-256-GCM 加密素材）
  │
  ▼
首次開啟 App → 顯示授權碼輸入畫面
  │
  ▼ 輸入 20 字元授權碼（顯示為 A7KMR-3NPX9-BWD5H-TJ2QF）
  │
  ▼ App 必須連網
POST /api/licensing/v1/activate
  body: { license_key, fingerprint, metadata }
  │
  ├─ 成功 → 回應包含：
  │   • license（status, expires_at, features, entitlements）
  │   • token（PASETO v4.public，exp = license.expires_at）
  │   • content_key（AES-256 素材解密金鑰，HTTPS 傳輸）
  │   • public_key_bundle（Ed25519 公鑰，離線驗 token 用）
  │   │
  │   ▼ App 儲存到安全儲存空間（flutter_secure_storage）：
  │   │  • content_key
  │   │  • token + public_key_bundle（用於離線驗證）
  │   │
  │   ▼ ═══ 正常使用（可完全離線）═══
  │   │
  │   ▼ 每次開啟 App：
  │   │  1. 用 public_key_bundle 離線驗證 token 簽章（Ed25519）
  │   │  2. 檢查 token 的 exp claim（= license 到期日）
  │   │  3. 有效 → 從安全儲存取 content_key → 即時解密素材
  │   │     • 小檔（文字/圖片）→ 解密到記憶體，直接使用
  │   │     • 大檔（影片）→ 即時串流解密或解密到暫存檔
  │   │  4. 無效 → 到期流程
  │   │
  │   ▼ ═══ 到期時 ═══
  │   │
  │   ▼ App 清除安全儲存中的 content_key + token
  │   ▼ 刪除所有暫存解密檔
  │   ▼ 顯示「授權已到期，請輸入新的授權碼」
  │   ▼ 使用者輸入新授權碼 → 連網 activate → 循環
  │
  └─ 失敗 → 顯示錯誤（無效金鑰 / 已過期 / 已達裝置上限）
```

---

## 後端實作

### 1. 自訂授權碼產生器（20 字元，A-Z + 0-9）

- 實作 `LicenseKeyGeneratorContract` 介面
- 僅使用 A-Z 大寫 + 0-9 數字，排除易混淆字元（O/0, I/1, L）
- 字元集 30 個，20 位 = 30^20 ≈ 3.5 × 10^29 種組合
- 顯示時每 5 字元插入 dash：`XXXXX-XXXXX-XXXXX-XXXXX`
- API 傳輸時移除 dash，純 20 字元

### 2. 動態 Token TTL（與授權到期日同步）

Token 的 `exp` 設定為與 `license.expires_at` 相同，而非全域固定天數。

`Licensing::issueToken()` 支援 `$options['ttl_days']` 參數，在自訂 Controller 中動態計算：

```php
$remainingDays = max(1, (int) ceil(now()->diffInDays($license->expires_at, absolute: true)));
$token = $this->licensing->issueToken($license, $usage, ['ttl_days' => $remainingDays]);
```

Config 中 `force_online_after_days` 設為 `9999`（不強制上線）。

### 3. content_key 機制

- content_key 存在 LicenseScope 或 Template 的 `meta` 中（產品級）
- License Observer 在建立 License 時從 Template/Scope 複製到 License meta
- 自訂 API Controller 在 `/activate` 回應中加入 content_key
- 同一產品的所有使用者取得同一把 content_key（因為 APK 中的素材用同一把 key 加密）

---

## content_key 安全性分析

### 所有人會得到同一把 content_key

是的。因為所有使用者拿到的是同一個 APK（同一份加密素材），解密金鑰必然是同一把。這是所有「統一包裝、客戶端解密」DRM 方案的本質限制——Netflix (Widevine)、Kindle、Apple Books 也面對同樣的結構。

### 安全層級分析

| 層級 | 保護了什麼 | 被繞過的條件 |
|---|---|---|
| **License Key 驗證** | 沒有有效授權碼就無法呼叫 `/activate` | 取得或猜到一組有效的授權碼 |
| **線上啟用** | 必須連到伺服器才能拿到 content_key | 攔截 HTTPS 回應（需繞過 cert pinning） |
| **content_key 保護** | 沒有 content_key 就無法解密素材 | 從已啟用的裝置中提取 content_key |
| **PASETO Token 到期** | 到期後 App 拒絕解密 | 修改 App 程式碼跳過到期檢查 |

### API 傳輸安全

在 HTTPS 下傳送 content_key 是安全的（等同於 API 回傳 access token 或 secret key）：

- ❌ 不能放在 PASETO token 中 — PASETO v4.public 的 payload 是明文（只簽章不加密）
- ❌ 從 license key 衍生 — 確定性演算，任何有 key 的人都能算出來
- ✅ HTTPS 一次性傳輸 + 安全儲存 — 最佳組合

### MVP 結論

目前的設計提供了合理的安全等級：
- 一般使用者無法繞過
- 阻擋未授權的使用
- 到期後自動失效
- 實作成本合理

完美的 DRM 不存在。即使是 Netflix 也接受一定程度的內容洩漏風險。

### 進階安全選項（非 MVP）

| 方案 | 做法 | 複雜度 |
|---|---|---|
| **定期更換 content_key** | 每個版本的 APK 用不同 key，舊版到期自動失效 | 低 |
| **Root 偵測** | App 偵測到 root 裝置時拒絕執行 | 中 |
| **程式碼混淆** | 用 ProGuard/R8 混淆解密邏輯 | 中 |
| **伺服器端串流** | 素材不存在 APK 中，每次使用從伺服器即時串流 | 高（但最安全） |

---

## Flutter App 端實作指引

### PASETO v4 離線驗證

PASETO v4.public 的離線驗證本質上只需要 Ed25519 簽章驗證 + Base64 解碼 + JSON 解析。

**Flutter 可用套件**：

| 套件 | 用途 |
|---|---|
| `cryptography: ^2.x` | Ed25519 簽章驗證 + AES-256-GCM 解密 |
| `pinenacl: ^0.x` | NaCl/libsodium API（Ed25519 + Curve25519） |
| `flutter_secure_storage` | 安全儲存 token/content_key |
| `video_player` / `better_player` | 影片播放 |
| `path_provider` | 取得暫存目錄路徑 |

**PASETO v4.public token 結構**：

```
v4.public.<base64url(payload + 64-byte-signature)>.<base64url(footer)>
```

- Header：固定 `v4.public.`
- Body：Base64url 編碼，最後 64 bytes 是 Ed25519 簽章，前面是 JSON payload
- Footer：Base64url 編碼的 JSON，含 `kid` 和 `chain`（certificate chain）

**離線驗證虛擬碼**：

```dart
Future<bool> verifyLicense() async {
  final token = await secureStorage.read(key: 'paseto_token');
  final bundleJson = await secureStorage.read(key: 'public_key_bundle');

  // 1. 拆解 PASETO token
  final parts = token!.split('.');
  assert(parts[0] == 'v4' && parts[1] == 'public');

  final bodyBytes = base64Url.decode(base64Url.normalize(parts[2]));

  // 2. 分離 payload 和 signature（最後 64 bytes 是 Ed25519 簽章）
  final payload = bodyBytes.sublist(0, bodyBytes.length - 64);
  final signature = bodyBytes.sublist(bodyBytes.length - 64);

  // 3. 用 signing public key 驗證簽章
  final ed25519 = Ed25519();
  final isValid = await ed25519.verify(
    payload,
    signature: Signature(signature, publicKey: publicKey),
  );

  // 4. 解析 claims 檢查到期
  final claims = jsonDecode(utf8.decode(payload));
  final exp = DateTime.parse(claims['exp']);
  if (DateTime.now().isAfter(exp)) return false;

  return isValid;
}
```

### 素材加密規格：分塊加密（Chunk-based AES-256-GCM）

AES-GCM 必須從頭到尾循序處理，如果影片整檔加密，使用者無法「快轉到第 30 分鐘」。解法是分塊加密：每個檔案切成固定大小的 chunk（建議 1 MB），各自獨立加密。

```
加密檔案格式：
[Header: 8 bytes magic + 4 bytes chunk_size + 12 bytes file_iv]
[Chunk 0: 12 bytes IV | encrypted_data | 16 bytes tag]
[Chunk 1: 12 bytes IV | encrypted_data | 16 bytes tag]
...
```

- 每個 chunk 的 IV = `HKDF(file_iv, chunk_index)`
- 支援 random access：跳到第 N 個 chunk 直接解密
- 記憶體效率：只快取 1 個 chunk（1 MB）

### App 端安全建議

- 使用 `flutter_secure_storage`（底層用 Android Keystore / iOS Keychain）儲存 content_key
- 啟用 certificate pinning 防止 MITM
- 解密後的素材只存在記憶體或暫存檔
- App 退出/到期時清除暫存檔
- 使用 ProGuard/R8 混淆解密相關程式碼

---

## Filament 管理介面 MVP 精簡

### 設計原則

管理員最常做的事是「建立授權 → 把授權碼給使用者」。MVP 介面應讓這個流程在 3 秒內完成。

### 建立授權的理想流程（3 步驟）

1. 選擇「授權範圍」（如果只有一個，自動選定）
2. 選擇「範本」（自動算出 `expires_at`）
3. 按「建立」→ 系統產生 20 字元授權碼 + content_key → 顯示授權碼可複製

### 隱藏的功能（MVP 不需要）

- License Scope：`description`、`default_grace_days`、`key_rotation_days` 區塊、`meta`
- License Template：`parent_template_id`、`slug`、trial 相關、grace 相關、`base_configuration`、`meta`
- License：`uid`、`licensable`、`status` 手動選擇、`max_usages`（固定 1）、`meta`、Renewals/Transfers/Trials RelationManagers
- License Usage：`client_type`、`meta`

---

## 後端實作清單

### 後端核心

| 檔案 | 動作 | 說明 |
|---|---|---|
| `app/Services/LicenseKeyGenerator.php` | 新建 | 自訂 20 字元金鑰產生器 |
| `config/licensing.php` | 修改 | key_generator 指向自訂類別 + 調整 offline_token |
| `app/Observers/LicenseObserver.php` | 新建 | License 建立時自動產生 content_key |
| `app/Providers/AppServiceProvider.php` | 修改 | 註冊 LicenseObserver |
| `app/Http/Controllers/Api/LicenseController.php` | 新建 | 動態 TTL + 回傳 content_key |
| `routes/api.php` | 新建 | 覆寫 activate endpoint |
| `bootstrap/app.php` | 修改 | 註冊 API 路由 |

### Filament 精簡

| 檔案 | 動作 | 說明 |
|---|---|---|
| `app/Filament/Resources/LicenseScopeResource.php` | 修改 | 隱藏 grace/rotation/meta 欄位 |
| `app/Filament/Resources/LicenseResource.php` | 新建 | 精簡建立表單 + 移除 Renewals/Transfers/Trials |
| `app/Filament/Resources/LicenseUsageResource.php` | 修改 | 隱藏 client_type/meta |
| `app/Filament/Resources/LicenseScopeResource/RelationManagers/TemplatesRelationManager.php` | 修改 | 隱藏 trial/grace/inheritance/meta |
| `app/Providers/Filament/CustomLicensingPlugin.php` | 修改 | 註冊 LicenseResource 覆寫 |

---

## 前置步驟

```bash
# 1. 建立 Root Key
vendor/bin/sail artisan licensing:keys:make-root

# 2. 建立 Signing Key
vendor/bin/sail artisan licensing:keys:issue-signing

# 3. 在 Filament 後台建立 License → 系統自動產生 20 字元授權碼 + content_key
```

## 驗證方式

1. 後台建立 License，確認授權碼為 20 字元（排除混淆字元）
2. 確認 License meta 中有 `content_key`（Base64 編碼的 32 bytes AES key）
3. `POST /api/licensing/v1/activate` 確認回應包含：
   - `content_key`（Base64 字串）
   - `token` 的 `exp` = `license.expires_at`（非固定天數）
   - `public_key_bundle`
4. 同 key + 不同 fingerprint → 回傳 `USAGE_LIMIT_REACHED`
5. 手動解析 token payload，確認 `license_expires_at` 與 `exp` 大致相同
