<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ValidateController extends LicenseController
{
    /**
     * 覆寫 vendor validateLicense()，額外加入 heartbeat（更新 last_seen_at）
     * 與 metadata 合併邏輯，讓 App 端單一端點完成狀態驗證 + 心跳回報。
     * 對齊版本：vendor 2.0 — client data 存放在 meta.client_data 下，
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
        }

        $usage->refresh();

        return $this->success([
            'license' => $this->formatLicense($license),
            'usage' => $this->formatUsage($usage),
        ]);
    }
}
