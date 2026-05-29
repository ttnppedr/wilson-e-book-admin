<?php

namespace Tests\Feature\Api;

use App\Models\Wordwall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use LucaLongo\Licensing\Contracts\TokenVerifier;
use Mockery;
use RuntimeException;
use Tests\Concerns\MocksLicenseTokenVerifier;
use Tests\TestCase;

/**
 * Wordwall List API feature 測試。
 *
 * 端點：GET /api/v1/wordwalls
 * 保護：throttle:api-wordwall + VerifyBearerToken (PASETO bearer token 簽章驗證)
 */
class WordwallListTest extends TestCase
{
    use MocksLicenseTokenVerifier;
    use RefreshDatabase;

    private const WORDWALL_URL = '/api/v1/wordwalls';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        $this->acceptAnyBearerToken();
    }

    public function test_rejects_without_authorization_header(): void
    {
        $response = $this->getJson(self::WORDWALL_URL);

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
            ->getJson(self::WORDWALL_URL);

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'INVALID_TOKEN');
    }

    public function test_returns_empty_list_when_no_wordwalls(): void
    {
        $response = $this->getJsonWithToken(self::WORDWALL_URL);

        $response->assertOk();
        $response->assertJsonPath('data', []);
        $response->assertJsonPath('meta.total', 0);
    }

    public function test_returns_wordwalls_sorted_by_sort_ascending(): void
    {
        Wordwall::factory()->create(['resource_url' => 'https://wordwall.net/resource/333', 'sort' => 3]);
        Wordwall::factory()->create(['resource_url' => 'https://wordwall.net/resource/111', 'sort' => 1]);
        Wordwall::factory()->create(['resource_url' => 'https://wordwall.net/resource/222', 'sort' => 2]);

        $response = $this->getJsonWithToken(self::WORDWALL_URL);

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

        $response = $this->getJsonWithToken(self::WORDWALL_URL);

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                '*' => ['sort', 'resource_url'],
            ],
            'meta' => ['total'],
        ]);
    }

    public function test_includes_etag_header_on_success(): void
    {
        Wordwall::factory()->create();

        $response = $this->getJsonWithToken(self::WORDWALL_URL);

        $response->assertOk();
        $this->assertNotNull(
            $response->headers->get('ETag'),
            '成功回應應帶 ETag header 供 client 做條件式請求'
        );
    }

    public function test_returns_304_when_if_none_match_matches_current_list(): void
    {
        Wordwall::factory()->create(['resource_url' => 'https://wordwall.net/resource/111', 'sort' => 1]);

        $first = $this->getJsonWithToken(self::WORDWALL_URL);
        $first->assertOk();
        $etag = $first->headers->get('ETag');
        $this->assertNotNull($etag);

        $second = $this->withHeaders([
            'Authorization' => 'Bearer stub-bearer-token',
            'If-None-Match' => $etag,
        ])->getJson(self::WORDWALL_URL);

        $second->assertStatus(304);
        $this->assertSame('', $second->getContent());
    }

    public function test_returns_200_with_new_etag_when_list_changes(): void
    {
        Wordwall::factory()->create(['resource_url' => 'https://wordwall.net/resource/111', 'sort' => 1]);

        $first = $this->getJsonWithToken(self::WORDWALL_URL);
        $first->assertOk();
        $oldEtag = $first->headers->get('ETag');
        $this->assertNotNull($oldEtag);

        // 清單變動（新增一筆）→ 帶舊 ETag 重新請求應拿到 200 與不同的 ETag
        Wordwall::factory()->create(['resource_url' => 'https://wordwall.net/resource/222', 'sort' => 2]);

        $second = $this->withHeaders([
            'Authorization' => 'Bearer stub-bearer-token',
            'If-None-Match' => $oldEtag,
        ])->getJson(self::WORDWALL_URL);

        $second->assertOk();
        $second->assertJsonCount(2, 'data');
        $this->assertNotSame($oldEtag, $second->headers->get('ETag'));
    }

    public function test_rate_limit_blocks_after_60_requests(): void
    {
        for ($i = 1; $i <= 60; $i++) {
            $response = $this->getJsonWithToken(self::WORDWALL_URL);
            $this->assertNotSame(
                429,
                $response->status(),
                "Wordwall request #{$i} 不應被限流，實際狀態 {$response->status()}"
            );
        }

        $response = $this->getJsonWithToken(self::WORDWALL_URL);
        $response->assertStatus(429);
        $response->assertJsonPath('error.code', 'RATE_LIMITED');
        $this->assertNotNull($response->headers->get('Retry-After'));
    }
}
