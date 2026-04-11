<?php

namespace Tests\Concerns;

use App\Models\License;
use Illuminate\Testing\TestResponse;
use LucaLongo\Licensing\Contracts\TokenVerifier;
use Mockery;

/**
 * Feature test helper：把 TokenVerifier 綁定換成一個會「對任何 bearer token 都通過」的 mock。
 *
 * 使用情境:
 *   - 測試 validate 路由上的 controller 邏輯時，不想依賴真實 signing key 基礎設施
 *   - VerifyLicenseToken middleware 的分支邏輯已由 unit test 覆蓋，feature test 只需
 *     確保 middleware 能通過即可
 */
trait MocksLicenseTokenVerifier
{
    /**
     * 綁一個 TokenVerifier mock，讓它對任何 token 都回傳符合指定 license_key / fingerprint
     * 的 claims。搭配 `postValidateWithToken()` 使用。
     *
     * @param  array<string, mixed>  $extraClaims  要覆寫或新增的 claim 欄位
     */
    protected function acceptAnyTokenFor(string $licenseKey, string $fingerprint, array $extraClaims = []): void
    {
        $verifier = Mockery::mock(TokenVerifier::class);
        $verifier->shouldReceive('verify')
            ->zeroOrMoreTimes()
            ->andReturn(array_merge([
                'license_key_hash' => License::hashKey($licenseKey),
                'usage_fingerprint' => $fingerprint,
                'status' => 'active',
                'exp' => now()->addDay()->toIso8601String(),
                'iat' => now()->toIso8601String(),
                'nbf' => now()->subMinute()->toIso8601String(),
            ], $extraClaims));

        $this->app->instance(TokenVerifier::class, $verifier);
    }

    /**
     * 用預先 stub 的 bearer token 呼叫 validate 端點。
     *
     * @param  array<string, mixed>  $body
     */
    protected function postValidateWithToken(array $body, string $token = 'stub-bearer-token'): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->postJson('/api/licensing/v1/validate', $body);
    }
}
