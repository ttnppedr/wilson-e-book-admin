<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use LucaLongo\Licensing\Http\Controllers\Api\LicenseController as BaseLicenseController;
use LucaLongo\Licensing\Models\License;
use LucaLongo\Licensing\Models\LicenseUsage;

class LicenseController extends BaseLicenseController
{
    public function activate(Request $request): JsonResponse
    {
        $payload = $this->validate($request, [
            'license_key' => ['required', 'string'],
            'fingerprint' => ['required', 'string'],
            'metadata' => ['nullable', 'array'],
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

        $metadata = $payload['metadata'] ?? [];

        try {
            $usage = $this->licensing->register($license, $payload['fingerprint'], $metadata);
        } catch (\RuntimeException $exception) {
            return $this->mapUsageException($exception);
        }

        $token = null;
        if ($license->isOfflineTokenEnabled() && $license->expires_at) {
            try {
                $remainingDays = max(1, (int) ceil(now()->diffInDays($license->expires_at, absolute: true)));
                $token = $this->licensing->issueToken($license, $usage, [
                    'ttl_days' => $remainingDays,
                ]);
            } catch (\Throwable $exception) {
                return $this->error('TOKEN_ISSUE_FAILED', $exception->getMessage(), 500);
            }
        }

        return $this->success($this->buildLicenseResponse($license->fresh(), $usage, $token));
    }

    protected function buildLicenseResponse(License $license, LicenseUsage $usage, ?string $token): array
    {
        $response = parent::buildLicenseResponse($license, $usage, $token);

        $meta = $license->meta ? (array) $license->meta : [];
        if (! empty($meta['content_key'])) {
            $response['content_key'] = $meta['content_key'];
        }

        return $response;
    }
}
