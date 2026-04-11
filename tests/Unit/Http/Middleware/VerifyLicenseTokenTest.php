<?php

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\VerifyLicenseToken;
use App\Models\License;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use LucaLongo\Licensing\Contracts\TokenVerifier;
use Mockery;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

/**
 * VerifyLicenseToken middleware 的單元測試。
 *
 * TokenVerifier 以 Mockery 替換，避免測試相依於真實 signing key 基礎設施；
 * 每條測試只驗證一種失敗/成功路徑。
 */
class VerifyLicenseTokenTest extends TestCase
{
    private const VALIDATE_URL = '/api/licensing/v1/validate';

    private const VALID_LICENSE_KEY = 'TEST-LICENSE-KEY-001';

    private const VALID_FINGERPRINT = 'device-fp-001';

    /**
     * 允許 middleware 裡的 Log::channel('api')->info/warning 呼叫通過，測試不關心訊息內容。
     */
    private function allowApiLogWrites(): void
    {
        $apiChannel = Mockery::mock();
        $apiChannel->shouldReceive('info')->zeroOrMoreTimes();
        $apiChannel->shouldReceive('warning')->zeroOrMoreTimes();

        Log::shouldReceive('channel')
            ->with('api')
            ->zeroOrMoreTimes()
            ->andReturn($apiChannel);
    }

    /**
     * 建構一個模擬「路由已被 middleware 攔截」的 Request，body 帶有 license_key/fingerprint。
     *
     * @param  array<string, mixed>  $overrides  要覆寫的 body 欄位
     */
    private function makeRequest(?string $bearer, array $overrides = []): Request
    {
        $body = array_merge([
            'license_key' => self::VALID_LICENSE_KEY,
            'fingerprint' => self::VALID_FINGERPRINT,
        ], $overrides);

        $server = [];
        if ($bearer !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$bearer;
        }

        return Request::create(self::VALIDATE_URL, 'POST', $body, [], [], $server);
    }

    /**
     * 產生一組合法的 claims，對應預設 license_key / fingerprint。
     *
     * @return array<string, mixed>
     */
    private function validClaims(): array
    {
        return [
            'license_key_hash' => License::hashKey(self::VALID_LICENSE_KEY),
            'usage_fingerprint' => self::VALID_FINGERPRINT,
            'status' => 'active',
            'exp' => '2099-01-01T00:00:00+00:00',
        ];
    }

    /**
     * 跑 middleware，回傳 (response, nextCalled)。
     *
     * @return array{0: Response, 1: bool, 2: ?Request}
     */
    private function runMiddleware(VerifyLicenseToken $middleware, Request $request): array
    {
        $nextCalled = false;
        $seenRequest = null;

        $response = $middleware->handle($request, function (Request $passed) use (&$nextCalled, &$seenRequest) {
            $nextCalled = true;
            $seenRequest = $passed;

            return response()->json(['ok' => true]);
        });

        return [$response, $nextCalled, $seenRequest];
    }

    public function test_rejects_when_authorization_header_is_missing(): void
    {
        $this->allowApiLogWrites();
        $verifier = Mockery::mock(TokenVerifier::class);
        $verifier->shouldNotReceive('verify');

        $middleware = new VerifyLicenseToken($verifier);
        [$response, $nextCalled] = $this->runMiddleware($middleware, $this->makeRequest(null));

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('INVALID_TOKEN', $response->getData(true)['error']['code']);
        $this->assertFalse($nextCalled);
    }

    public function test_rejects_when_token_verifier_throws(): void
    {
        $this->allowApiLogWrites();
        $verifier = Mockery::mock(TokenVerifier::class);
        $verifier->shouldReceive('verify')
            ->once()
            ->with('bad-token')
            ->andThrow(new RuntimeException('signature invalid'));

        $middleware = new VerifyLicenseToken($verifier);
        [$response, $nextCalled] = $this->runMiddleware($middleware, $this->makeRequest('bad-token'));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('INVALID_TOKEN', $response->getData(true)['error']['code']);
        $this->assertFalse($nextCalled);
    }

    public function test_rejects_when_license_key_is_missing_from_body(): void
    {
        $this->allowApiLogWrites();
        $verifier = Mockery::mock(TokenVerifier::class);
        $verifier->shouldReceive('verify')->once()->andReturn($this->validClaims());

        $middleware = new VerifyLicenseToken($verifier);
        [$response, $nextCalled] = $this->runMiddleware(
            $middleware,
            $this->makeRequest('good-token', ['license_key' => ''])
        );

        $this->assertSame(401, $response->getStatusCode());
        $this->assertFalse($nextCalled);
    }

    public function test_rejects_when_fingerprint_is_missing_from_body(): void
    {
        $this->allowApiLogWrites();
        $verifier = Mockery::mock(TokenVerifier::class);
        $verifier->shouldReceive('verify')->once()->andReturn($this->validClaims());

        $middleware = new VerifyLicenseToken($verifier);
        [$response, $nextCalled] = $this->runMiddleware(
            $middleware,
            $this->makeRequest('good-token', ['fingerprint' => ''])
        );

        $this->assertSame(401, $response->getStatusCode());
        $this->assertFalse($nextCalled);
    }

    public function test_rejects_when_license_key_hash_in_claims_does_not_match_request(): void
    {
        $this->allowApiLogWrites();
        $claims = $this->validClaims();
        $claims['license_key_hash'] = License::hashKey('SOMEONE-ELSES-KEY');

        $verifier = Mockery::mock(TokenVerifier::class);
        $verifier->shouldReceive('verify')->once()->andReturn($claims);

        $middleware = new VerifyLicenseToken($verifier);
        [$response, $nextCalled] = $this->runMiddleware($middleware, $this->makeRequest('good-token'));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('INVALID_TOKEN', $response->getData(true)['error']['code']);
        $this->assertFalse($nextCalled);
    }

    public function test_rejects_when_fingerprint_in_claims_does_not_match_request(): void
    {
        $this->allowApiLogWrites();
        $claims = $this->validClaims();
        $claims['usage_fingerprint'] = 'a-different-device';

        $verifier = Mockery::mock(TokenVerifier::class);
        $verifier->shouldReceive('verify')->once()->andReturn($claims);

        $middleware = new VerifyLicenseToken($verifier);
        [$response, $nextCalled] = $this->runMiddleware($middleware, $this->makeRequest('good-token'));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertFalse($nextCalled);
    }

    public function test_passes_through_and_injects_claims_when_everything_matches(): void
    {
        $this->allowApiLogWrites();
        $claims = $this->validClaims();

        $verifier = Mockery::mock(TokenVerifier::class);
        $verifier->shouldReceive('verify')->once()->with('good-token')->andReturn($claims);

        $middleware = new VerifyLicenseToken($verifier);
        [$response, $nextCalled, $seenRequest] = $this->runMiddleware(
            $middleware,
            $this->makeRequest('good-token')
        );

        $this->assertTrue($nextCalled);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($claims, $seenRequest?->attributes->get('license_claims'));
    }
}
