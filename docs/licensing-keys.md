# 授權簽章金鑰管理與部署（含 Laravel Cloud）

本文說明 Wilson e-book 授權系統的金鑰架構、如何在 Laravel Cloud（或其他臨時
檔案系統環境）安全部署，以及如何把 root 公鑰交給 App 端內嵌。

> 相關：`docs/deployment.md`（新機部署清單第 4 節）、`config/licensing.php`
> 的 `crypto.keystore`、App 端 `lib/core/constants/license_config.dart`。

---

## 1. 金鑰架構：兩對金鑰、一個信任錨

授權系統用**憑證鏈**，牽涉兩對 Ed25519 金鑰：

```
① root 金鑰對
   root 私鑰（後端 DB，加密）  ←─ 一對 ─→  root 公鑰（內嵌在 App 二進位）
② signing 金鑰對
   signing 私鑰（後端 DB，加密）←─ 一對 ─→  signing 公鑰（隨憑證動態下發）
```

- 後端用 **root 私鑰** 簽發「signing 憑證」（背書 signing 公鑰可信）。
- 後端用 **signing 私鑰** 簽 PASETO token。
- App 只內嵌 **root 公鑰**，用它驗憑證 → 取出 signing 公鑰 → 驗 token。

**安全鐵則：**

- **私鑰永不離開後端**——App、版控、`.env.example`、任何交付物都不得出現私鑰。
  App 只做驗證、不簽發，本就不需要私鑰。
- **root 公鑰非秘密**，內嵌進 App、進版控皆安全（只能驗證、不能簽發）。
- App 內嵌的 root 公鑰**必須從實際簽授權的那台後端** export，才保證同一對。

**信任錨的意義**：這道設計修補了 App 端「驗簽公鑰原本取自 API 回應」的重大漏洞
（假 server 可自簽 token 繞過授權）。App 改用內嵌 root 公鑰當唯一信任來源。

---

## 2. 為什麼 Laravel Cloud 必須用 `database` keystore

`config/licensing.php` 的 `crypto.keystore.driver`：

| driver | 私鑰存放 | 適用 |
| --- | --- | --- |
| `files` | `storage/app/licensing/keys`（檔案） | 本機 / 有持久磁碟的自管主機 |
| `database` | `licensing_keys.private_key_encrypted`（DB 欄位，經 passphrase 加密） | **Laravel Cloud 等臨時檔案系統** |

**Laravel Cloud 的 `storage/` 不跨部署保留**：若用 `files`，一次 redeploy／容器重啟就
清掉 root 私鑰 → 全裝置需重新啟用，且 App 內嵌的 root 公鑰永久失配（因為得重生
root）。對 B2B 授權是災難。故本專案預設已改為 `database`。

加密後的私鑰存進受管 DB（有備份、跨部署穩定），且以 `LICENSING_KEY_PASSPHRASE`
加密——DB dump 外洩而無 passphrase 仍解不出私鑰。安全性拆成「DB 存取權 + passphrase」
兩把，需同時洩漏才失守。

---

## 3. Laravel Cloud 部署步驟

### 3.1 設定環境變數（Laravel Cloud 後台 → Environment）

於 Laravel Cloud 的環境變數介面設定（**不要**寫進部署的 `.env` 檔，該檔會被覆蓋）：

| 變數 | 說明 |
| --- | --- |
| `LICENSING_KEY_PASSPHRASE` | 加密 root/signing 私鑰用。以下方指令產生後貼入，**務必另存密碼管理器**。遺失 = 私鑰永久無法解密、須重建 root。 |
| `APP_KEY` | 保持穩定。`LICENSING_KEY_SALT` 未設時預設用它；變動會使既有授權碼雜湊全失效。 |
| `LICENSING_KEY_SALT`（選） | 若要與 `APP_KEY` 解耦可顯式設定；設了就別再變。 |
| `LICENSING_TOKEN_ISSUER` | 預設 `wilson-ebook-admin`，須與 App 端 `LicenseConstants.expectedIssuer` 一致。 |

產生 passphrase（本機或 Cloud console 皆可，`--show` 只印不寫檔）：

```bash
php artisan licensing-passphrase:generate --show
```

### 3.2 一次性建立金鑰（DB 與 passphrase 就緒後）

於 Laravel Cloud 的 command runner / console 執行：

```bash
php artisan migrate                               # 確保 licensing_keys 表存在
php artisan licensing:keys:make-root              # 產 root 金鑰對（私鑰加密進 DB）
php artisan licensing:keys:issue-signing --days=3650   # 由 root 簽發 10 年期 signing key
php artisan licensing:keys:list                   # 確認 root 與 signing 均 active
```

> `make-root` **每個環境只跑一次**。重跑會產生新 root，導致 App 內嵌公鑰失配。

### 3.3 匯出 root 公鑰交給 App 端

```bash
php artisan licensing:keys:export --include-chain
```

從輸出的 `root` 區塊取兩個值：

- `public_key` → 填入 App `lib/core/constants/license_config.dart` 的
  `_prodRootPublicKey`（staging 則 `_stagingRootPublicKey`）。
- `kid` → 填入對應的 `_prodRootKid` / `_stagingRootKid`。

App 端再以對應環境建置：

```bash
# 於 App repo（wilson-e-book-english）
dart run tool/build_release.dart --env=prod        # 或 --env=staging（預設）
```

> ⚠️ 內嵌的 root 公鑰**必須從實際簽該環境授權的後端** export。staging 與 prod
> 各有獨立 root，App 各內嵌各的，互不通用（跨環境驗不過是刻意的保護）。

---

## 4. 金鑰輪替與備份

- **root 只產一次、勿輕易輪替**：重產 root = 換 App 內嵌公鑰 = 須重新出貨 App。
- **要輪替就輪替 signing key**（App 免更新，因為只釘 root）：

  ```bash
  php artisan licensing:keys:rotate          # 產新 signing key、由同一 root 簽發
  php artisan licensing:keys:revoke <kid>    # 撤銷舊 signing key（視需要）
  ```

  新 signing key 的憑證由現有 root 簽發，App 用內嵌 root 公鑰即可驗過新鏈。

- **務必備份**：`LICENSING_KEY_PASSPHRASE`（存密碼管理器）＋ 資料庫（含
  `licensing_keys` 表）。兩者缺一即無法還原 root 私鑰。

---

## 5. 疑難排解

| 症狀 | 可能原因 |
| --- | --- |
| App activate 後憑證鏈驗證失敗 | App 內嵌 root 公鑰與後端 root 不是同一對（環境搞錯／後端重生過 root） |
| Redeploy 後所有裝置無法啟用 | keystore 仍為 `files`，私鑰隨 `storage/` 被清；改 `database` 並重建 root（見上） |
| `No active root key found` | 未跑 `licensing:keys:make-root`，或 passphrase 變更導致既有私鑰無法解密 |
| `No active signing key found` | 未跑 `licensing:keys:issue-signing`，或 signing key 已過期／被撤銷 |
| 授權碼雜湊全部對不上 | `APP_KEY`（或 `LICENSING_KEY_SALT`）變動 |
