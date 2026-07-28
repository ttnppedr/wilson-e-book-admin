<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;

class ValidateController extends LicenseController
{
    /**
     * client 可回報的 `data` 鍵名白名單。
     *
     * 白名單而非黑名單：日後新增用途要顯式加進來，避免又變成無界的傾倒場。
     */
    private const CLIENT_DATA_ALLOWED_KEYS = [
        'app_version',
        'device_name',
        'os_version',
    ];

    /** `data` 單一值的最大字元數 */
    private const CLIENT_DATA_MAX_VALUE_LENGTH = 255;

    /**
     * 覆寫 vendor validateLicense()，額外加入 heartbeat（更新 last_seen_at）
     * 與 metadata 合併邏輯，讓 App 端單一端點完成狀態驗證 + 心跳回報。
     * 對齊版本：vendor 2.2.1 — client data 存放在 meta.client_data 下，
     * 避免覆寫內部 metadata。
     */
    public function validateLicense(Request $request): JsonResponse
    {
        $payload = $this->validate($request, [
            'license_key' => ['required', 'string'],
            'fingerprint' => ['required', 'string', 'max:255'],
            // `data` 會被 array_merge 累加進 usage.meta，且 LicenseUsage 是
            // Auditable（每次變更在 audits 再寫一份 old+new，約 2 倍放大）。
            // 沒有 schema 時，持合法 token 者可反覆 POST 不同鍵名的大 JSON
            // （php.ini post_max_size=100M）把單列撐爆。因此限制鍵名白名單、
            // 鍵數與值長度。App 端目前不送此欄位，保留純為向後相容。
            //
            // 鍵數上限直接由白名單長度推導：過濾後本來就不可能超過白名單大小，
            // 另立一個較大的魔術數字只會讓「實際上限是多少」需要讀三處才能推出。
            'data' => ['nullable', 'array', 'max:'.count(self::CLIENT_DATA_ALLOWED_KEYS)],
            'data.*' => ['nullable', 'string', 'max:'.self::CLIENT_DATA_MAX_VALUE_LENGTH],
        ]);

        if (isset($payload['data'])) {
            $payload['data'] = Arr::only($payload['data'], self::CLIENT_DATA_ALLOWED_KEYS);
        }

        $license = $this->findLicense($payload['license_key']);
        if (! $license) {
            return $this->error('INVALID_KEY', 'License key is invalid or not found', 404);
        }

        if ($license->status->canActivate()) {
            $license->activate();
            $license->refresh();
        }

        if ($response = $this->guardLicenseState($license)) {
            return $response;
        }

        $usage = $this->usageRegistrar->findByFingerprint($license, $payload['fingerprint']);

        if (! $usage || ! $usage->isActive()) {
            return $this->error('FINGERPRINT_MISMATCH', 'Fingerprint does not match an active usage for this license', 403);
        }

        $this->licensing->heartbeat($usage);

        if (! empty($payload['data'])) {
            $currentMeta = (array) ($usage->meta ?? []);
            $currentMeta['client_data'] = array_merge(
                $currentMeta['client_data'] ?? [],
                $payload['data'],
            );
            $usage->fill(['meta' => $currentMeta]);
            $usage->save();
            $usage->refresh();
        }

        // 簽發一個「驗證收據」token：與 activate 的 token 同樣由 signing key 簽章、
        // footer 帶完整憑證鏈，但**不含 wrapped_content_key**（App 已持有 content
        // key）。其 force_online_after 由 issue() 重算為 now + 設定窗，等於每次成功
        // validate 都把強制上線截止點往後推。App 用內嵌 root 驗證後替換已存 token，
        // 使「最近是否線上驗證過」的權威變成不可竄改的簽名 claim（修補 HIGH #6：
        // 原本 App 依賴 SecureStorage 明文 lastValidateAt，root 裝置可竄改延後上線）。
        $ttlDays = $license->expires_at !== null
            ? max(1, (int) ceil(now()->diffInDays($license->expires_at, absolute: true)))
            : self::PERPETUAL_TOKEN_TTL_DAYS;

        $validationToken = $this->licensing->issueToken($license, $usage, [
            'ttl_days' => $ttlDays,
        ]);

        return $this->success([
            'license' => $this->formatLicense($license),
            'usage' => $this->formatUsage($usage),
            'validation_token' => $validationToken,
        ]);
    }
}
