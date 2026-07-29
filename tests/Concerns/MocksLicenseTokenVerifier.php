<?php

namespace Tests\Concerns;

use App\Models\License;
use Illuminate\Testing\TestResponse;
use LucaLongo\Licensing\Contracts\TokenVerifier;
use LucaLongo\Licensing\Enums\LicenseStatus;
use Mockery;

/**
 * Feature test helper：把 TokenVerifier 綁定換成一個會「對任何 bearer token 都通過」的 mock。
 *
 * 使用情境:
 *   - 測試 API 路由上的 controller 邏輯時，不想依賴真實 signing key 基礎設施
 *   - `acceptAnyTokenFor()` 回傳包含 license_key_hash / fingerprint 的 claims
 *   - `acceptAnyBearerToken()` 回傳最小 claims，適用於僅驗簽章的端點
 */
trait MocksLicenseTokenVerifier
{
    /** 本次測試中 `acceptAnyBearerToken()` 的 claims 所指向的授權 */
    private ?License $bearerTokenLicense = null;

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
     * 綁一個 TokenVerifier mock，讓它對任何 token 都回傳合法 claims。
     * 適用於內容端點（VerifyBearerToken + EnsureLicenseUsable）的測試。
     *
     * claims 會帶上 `license_id`，指向一張由 `bearerTokenLicense()` 建立的可用
     * 授權——內容端點的 EnsureLicenseUsable 會據此查 DB 確認授權現況，沒有這個
     * claim 一律 401。要測「授權失效後 token 就該被擋」的情境，取出
     * `bearerTokenLicense()` 改狀態即可。
     *
     * @param  array<string, mixed>  $extraClaims
     */
    protected function acceptAnyBearerToken(array $extraClaims = []): void
    {
        $verifier = Mockery::mock(TokenVerifier::class);
        $verifier->shouldReceive('verify')
            ->zeroOrMoreTimes()
            ->andReturn(array_merge([
                'license_id' => $this->bearerTokenLicense()->getKey(),
                'status' => 'active',
                'exp' => now()->addDay()->toIso8601String(),
                'iat' => now()->toIso8601String(),
                'nbf' => now()->subMinute()->toIso8601String(),
            ], $extraClaims));

        $this->app->instance(TokenVerifier::class, $verifier);
    }

    /**
     * 取得（必要時建立）`acceptAnyBearerToken()` 所指向的可用授權。
     *
     * 同一個測試內重複呼叫回同一張，讓測試可以先 `acceptAnyBearerToken()`
     * 再把這張授權改成 cancelled/expired 來驗證擋門行為。
     */
    protected function bearerTokenLicense(): License
    {
        return $this->bearerTokenLicense ??= License::create([
            'key_hash' => License::hashKey('BEARER-'.bin2hex(random_bytes(8))),
            'status' => LicenseStatus::Active,
            'activated_at' => now(),
            'expires_at' => now()->addDays(30),
            'max_usages' => 1,
            'meta' => [],
        ]);
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

    /**
     * 用預先 stub 的 bearer token 發送 GET 請求。
     */
    protected function getJsonWithToken(string $url, string $token = 'stub-bearer-token'): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson($url);
    }
}
