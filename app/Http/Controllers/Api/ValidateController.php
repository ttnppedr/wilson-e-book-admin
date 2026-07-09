<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ValidateController extends LicenseController
{
    /**
     * 覆寫 vendor validateLicense()，額外加入 heartbeat（更新 last_seen_at）
     * 與 metadata 合併邏輯，讓 App 端單一端點完成狀態驗證 + 心跳回報。
     * 對齊版本：vendor 2.1.1 — client data 存放在 meta.client_data 下，
     * 避免覆寫內部 metadata。
     */
    public function validateLicense(Request $request): JsonResponse
    {
        $payload = $this->validate($request, [
            'license_key' => ['required', 'string'],
            'fingerprint' => ['required', 'string', 'max:255'],
            'data' => ['nullable', 'array'],
        ]);

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
