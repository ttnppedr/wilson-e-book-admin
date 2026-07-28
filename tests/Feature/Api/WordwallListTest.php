<?php

/**
 * Wordwall List API feature 測試。
 *
 * 端點：GET /api/v1/wordwalls
 * 保護：throttle:api-wordwall + VerifyBearerToken (PASETO bearer token 簽章驗證)
 */

use App\Models\Wordwall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use LucaLongo\Licensing\Contracts\TokenVerifier;
use Tests\Concerns\MocksLicenseTokenVerifier;

uses(MocksLicenseTokenVerifier::class, RefreshDatabase::class);

$wordwallUrl = '/api/v1/wordwalls';

beforeEach(function (): void {
    Cache::flush();
    $this->acceptAnyBearerToken();
});

test('rejects without authorization header', function () use ($wordwallUrl): void {
    $response = $this->getJson($wordwallUrl);

    $response->assertStatus(401);
    $response->assertJsonPath('error.code', 'INVALID_TOKEN');
});

test('rejects with invalid token', function () use ($wordwallUrl): void {
    $verifier = Mockery::mock(TokenVerifier::class);
    $verifier->shouldReceive('verify')
        ->once()
        ->andThrow(new RuntimeException('paseto parse failed'));
    $this->app->instance(TokenVerifier::class, $verifier);

    $response = $this->withHeaders(['Authorization' => 'Bearer bad-token'])
        ->getJson($wordwallUrl);

    $response->assertStatus(401);
    $response->assertJsonPath('error.code', 'INVALID_TOKEN');
});

test('returns empty list when no wordwalls', function () use ($wordwallUrl): void {
    $response = $this->getJsonWithToken($wordwallUrl);

    $response->assertOk();
    $response->assertJsonPath('data', []);
    $response->assertJsonPath('meta.total', 0);
});

test('returns wordwalls sorted by sort ascending', function () use ($wordwallUrl): void {
    Wordwall::factory()->create(['resource_url' => 'https://wordwall.net/resource/333', 'sort' => 3]);
    Wordwall::factory()->create(['resource_url' => 'https://wordwall.net/resource/111', 'sort' => 1]);
    Wordwall::factory()->create(['resource_url' => 'https://wordwall.net/resource/222', 'sort' => 2]);

    $response = $this->getJsonWithToken($wordwallUrl);

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
});

test('response contains expected structure', function () use ($wordwallUrl): void {
    Wordwall::factory()->create();

    $response = $this->getJsonWithToken($wordwallUrl);

    $response->assertOk();
    $response->assertJsonStructure([
        'data' => [
            '*' => ['id', 'sort', 'resource_url'],
        ],
        'meta' => ['total'],
    ]);
});

test('includes etag header on success', function () use ($wordwallUrl): void {
    Wordwall::factory()->create();

    $response = $this->getJsonWithToken($wordwallUrl);

    $response->assertOk();
    $this->assertNotNull(
        $response->headers->get('ETag'),
        '成功回應應帶 ETag header 供 client 做條件式請求'
    );
});

test('returns 304 when if none match matches current list', function () use ($wordwallUrl): void {
    Wordwall::factory()->create(['resource_url' => 'https://wordwall.net/resource/111', 'sort' => 1]);

    $first = $this->getJsonWithToken($wordwallUrl);
    $first->assertOk();
    $etag = $first->headers->get('ETag');
    $this->assertNotNull($etag);

    $second = $this->withHeaders([
        'Authorization' => 'Bearer stub-bearer-token',
        'If-None-Match' => $etag,
    ])->getJson($wordwallUrl);

    $second->assertStatus(304);
    $this->assertSame('', $second->getContent());
});

test('returns 200 with new etag when list changes', function () use ($wordwallUrl): void {
    Wordwall::factory()->create(['resource_url' => 'https://wordwall.net/resource/111', 'sort' => 1]);

    $first = $this->getJsonWithToken($wordwallUrl);
    $first->assertOk();
    $oldEtag = $first->headers->get('ETag');
    $this->assertNotNull($oldEtag);

    // 清單變動（新增一筆）→ 帶舊 ETag 重新請求應拿到 200 與不同的 ETag
    Wordwall::factory()->create(['resource_url' => 'https://wordwall.net/resource/222', 'sort' => 2]);

    $second = $this->withHeaders([
        'Authorization' => 'Bearer stub-bearer-token',
        'If-None-Match' => $oldEtag,
    ])->getJson($wordwallUrl);

    $second->assertOk();
    $second->assertJsonCount(2, 'data');
    $this->assertNotSame($oldEtag, $second->headers->get('ETag'));
});

test('rate limit blocks after 60 requests', function () use ($wordwallUrl): void {
    for ($i = 1; $i <= 60; $i++) {
        $response = $this->getJsonWithToken($wordwallUrl);
        $this->assertNotSame(
            429,
            $response->status(),
            "Wordwall request #{$i} 不應被限流，實際狀態 {$response->status()}"
        );
    }

    $response = $this->getJsonWithToken($wordwallUrl);
    $response->assertStatus(429);
    $response->assertJsonPath('error.code', 'RATE_LIMITED');
    $this->assertNotNull($response->headers->get('Retry-After'));
});
