<?php

namespace Tests\Feature\Api;

use App\Models\Wordwall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use LucaLongo\Licensing\Contracts\TokenVerifier;
use LucaLongo\Licensing\Enums\LicenseStatus;
use LucaLongo\Licensing\Models\License;
use Mockery;
use RuntimeException;
use Tests\Concerns\MocksLicenseTokenVerifier;
use Tests\TestCase;

/**
 * Wordwall List API feature 測試。
 *
 * 端點：POST /api/v1/wordwalls
 * 保護：throttle:api-wordwall + VerifyLicenseToken (PASETO bearer token + body 交叉比對)
 */
class WordwallListTest extends TestCase
{
    use MocksLicenseTokenVerifier;
    use RefreshDatabase;

    private const WORDWALL_URL = '/api/v1/wordwalls';

    private string $licenseKey;

    private string $fingerprint = 'wordwall-test-fp';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->licenseKey = 'WORDWALL'.bin2hex(random_bytes(6));
        License::create([
            'key_hash' => License::hashKey($this->licenseKey),
            'status' => LicenseStatus::Active,
            'activated_at' => now(),
            'expires_at' => now()->addDays(30),
            'max_usages' => 1,
            'meta' => [],
        ]);

        $this->acceptAnyTokenFor($this->licenseKey, $this->fingerprint);
    }

    private function postWordwallsWithToken(array $extraBody = []): TestResponse
    {
        return $this->withHeaders(['Authorization' => 'Bearer stub-bearer-token'])
            ->postJson(self::WORDWALL_URL, array_merge([
                'license_key' => $this->licenseKey,
                'fingerprint' => $this->fingerprint,
            ], $extraBody));
    }

    public function test_rejects_without_authorization_header(): void
    {
        $response = $this->postJson(self::WORDWALL_URL, [
            'license_key' => $this->licenseKey,
            'fingerprint' => $this->fingerprint,
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'INVALID_TOKEN');
    }

    public function test_rejects_with_invalid_token(): void
    {
        $verifier = Mockery::mock(TokenVerifier::class);
        $verifier->shouldReceive('verify')
            ->once()
            ->andThrow(new RuntimeException('paseto parse failed'));
        $this->app->instance(TokenVerifier::class, $verifier);

        $response = $this->withHeaders(['Authorization' => 'Bearer bad-token'])
            ->postJson(self::WORDWALL_URL, [
                'license_key' => $this->licenseKey,
                'fingerprint' => $this->fingerprint,
            ]);

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'INVALID_TOKEN');
    }

    public function test_returns_empty_list_when_no_wordwalls(): void
    {
        $response = $this->postWordwallsWithToken();

        $response->assertOk();
        $response->assertJsonPath('data', []);
        $response->assertJsonPath('meta.total', 0);
    }

    public function test_returns_wordwalls_sorted_by_sort_ascending(): void
    {
        Wordwall::factory()->create(['resource_url' => 'https://wordwall.net/resource/333', 'sort' => 3]);
        Wordwall::factory()->create(['resource_url' => 'https://wordwall.net/resource/111', 'sort' => 1]);
        Wordwall::factory()->create(['resource_url' => 'https://wordwall.net/resource/222', 'sort' => 2]);

        $response = $this->postWordwallsWithToken();

        $response->assertOk();
        $response->assertJsonCount(3, 'data');
        $response->assertJsonPath('meta.total', 3);

        $data = $response->json('data');
        $this->assertSame(1, $data[0]['sort']);
        $this->assertSame(2, $data[1]['sort']);
        $this->assertSame(3, $data[2]['sort']);

        $this->assertSame('https://wordwall.net/resource/111', $data[0]['resource_url']);
        $this->assertSame('https://wordwall.net/resource/222', $data[1]['resource_url']);
        $this->assertSame('https://wordwall.net/resource/333', $data[2]['resource_url']);
    }

    public function test_response_contains_expected_structure(): void
    {
        Wordwall::factory()->create();

        $response = $this->postWordwallsWithToken();

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                '*' => ['sort', 'resource_url'],
            ],
            'meta' => ['total'],
        ]);
    }

    public function test_rate_limit_blocks_after_60_requests(): void
    {
        for ($i = 1; $i <= 60; $i++) {
            $response = $this->postWordwallsWithToken();
            $this->assertNotSame(
                429,
                $response->status(),
                "Wordwall request #{$i} 不應被限流，實際狀態 {$response->status()}"
            );
        }

        $response = $this->postWordwallsWithToken();
        $response->assertStatus(429);
        $response->assertJsonPath('error.code', 'RATE_LIMITED');
        $this->assertNotNull($response->headers->get('Retry-After'));
    }
}
