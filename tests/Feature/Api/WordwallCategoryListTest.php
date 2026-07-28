<?php

/**
 * Wordwall Category List API feature 測試。
 *
 * 端點：GET /api/v1/wordwall-categories
 * 保護：throttle:api-wordwall + VerifyBearerToken (PASETO bearer token 簽章驗證)
 * 回應：巢狀 {data:[{name, image_url, sort, wordwalls:[{sort, resource_url}]}], meta:{total}}
 */

use App\Models\Wordwall;
use App\Models\WordwallCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LucaLongo\Licensing\Contracts\TokenVerifier;
use Tests\Concerns\MocksLicenseTokenVerifier;

uses(MocksLicenseTokenVerifier::class, RefreshDatabase::class);

$categoriesUrl = '/api/v1/wordwall-categories';

beforeEach(function (): void {
    Cache::flush();
    // 模擬生產環境的 S3 相容 disk（有 url 設定），讓 image_url 產生絕對 CDN 網址。
    config(['filesystems.default' => 's3']);
    Storage::fake('s3', ['url' => 'https://cdn.test/wordwall']);
    $this->acceptAnyBearerToken();
});

test('rejects without authorization header', function () use ($categoriesUrl): void {
    $response = $this->getJson($categoriesUrl);

    $response->assertStatus(401);
    $response->assertJsonPath('error.code', 'INVALID_TOKEN');
});

test('rejects with invalid token', function () use ($categoriesUrl): void {
    $verifier = Mockery::mock(TokenVerifier::class);
    $verifier->shouldReceive('verify')
        ->once()
        ->andThrow(new RuntimeException('paseto parse failed'));
    $this->app->instance(TokenVerifier::class, $verifier);

    $response = $this->withHeaders(['Authorization' => 'Bearer bad-token'])
        ->getJson($categoriesUrl);

    $response->assertStatus(401);
    $response->assertJsonPath('error.code', 'INVALID_TOKEN');
});

test('returns empty list when no categories', function () use ($categoriesUrl): void {
    $response = $this->getJsonWithToken($categoriesUrl);

    $response->assertOk();
    $response->assertJsonPath('data', []);
    $response->assertJsonPath('meta.total', 0);
});

test('returns categories sorted by sort ascending', function () use ($categoriesUrl): void {
    WordwallCategory::factory()->create(['name' => '分類C', 'sort' => 3]);
    WordwallCategory::factory()->create(['name' => '分類A', 'sort' => 1]);
    WordwallCategory::factory()->create(['name' => '分類B', 'sort' => 2]);

    $response = $this->getJsonWithToken($categoriesUrl);

    $response->assertOk();
    $response->assertJsonCount(3, 'data');
    $response->assertJsonPath('meta.total', 3);

    $data = $response->json('data');
    $this->assertSame('分類A', $data[0]['name']);
    $this->assertSame('分類B', $data[1]['name']);
    $this->assertSame('分類C', $data[2]['name']);
});

test('response contains expected nested structure', function () use ($categoriesUrl): void {
    $category = WordwallCategory::factory()->create();
    Wordwall::factory()->create(['wordwall_category_id' => $category->id]);

    $response = $this->getJsonWithToken($categoriesUrl);

    $response->assertOk();
    $response->assertJsonStructure([
        'data' => [
            '*' => [
                'id',
                'name',
                'image_url',
                'sort',
                'wordwalls' => [
                    '*' => ['id', 'sort', 'resource_url'],
                ],
            ],
        ],
        'meta' => ['total'],
    ]);
});

test('image url points to token protected redirect endpoint', function () use ($categoriesUrl): void {
    // image_url 指向穩定的轉址端點（非 S3 簽章網址），實際 presigned URL 由該端點即時簽發。
    $category = WordwallCategory::factory()->create([
        'image_path' => 'wordwall-categories/math.png',
        'sort' => 1,
    ]);

    $response = $this->getJsonWithToken($categoriesUrl);

    $response->assertOk();
    $this->assertSame(
        route('v1.wordwall-categories.image', [
            'wordwallCategory' => $category->id,
            'v' => substr(md5('wordwall-categories/math.png'), 0, 8),
        ]),
        $response->json('data.0.image_url')
    );
});

test('image url is absolute and independent of filesystem disk', function () use ($categoriesUrl): void {
    // image_url 不再經 Storage::url()，故與 disk 無關：永遠是指向轉址端點的絕對網址，
    // 且不外洩原始儲存路徑（image_path）。
    config(['filesystems.default' => 'local']);

    $category = WordwallCategory::factory()->create([
        'image_path' => 'wordwall-categories/math.png',
        'sort' => 1,
    ]);

    $response = $this->getJsonWithToken($categoriesUrl);

    $response->assertOk();
    $imageUrl = $response->json('data.0.image_url');
    $this->assertStringStartsWith('http', $imageUrl);
    $this->assertStringContainsString('/api/v1/wordwall-categories/'.$category->id.'/image', $imageUrl);
    $this->assertStringNotContainsString('wordwall-categories/math.png', $imageUrl);
});

test('etag changes when category image is swapped', function () use ($categoriesUrl): void {
    // 換圖（image_path 變動）→ image_url 的 v 參數改變 → 清單 body 變動 → ETag 失效，
    // 讓背景重整的 App 得知需重抓清單與新圖；避免取到舊圖快取。
    $category = WordwallCategory::factory()->create([
        'image_path' => 'wordwall-categories/old.png',
        'sort' => 1,
    ]);

    $first = $this->getJsonWithToken($categoriesUrl);
    $first->assertOk();
    $oldEtag = $first->headers->get('ETag');
    $this->assertNotNull($oldEtag);

    $category->update(['image_path' => 'wordwall-categories/new.png']);

    $second = $this->withHeaders([
        'Authorization' => 'Bearer stub-bearer-token',
        'If-None-Match' => $oldEtag,
    ])->getJson($categoriesUrl);

    $second->assertOk();
    $this->assertNotSame($oldEtag, $second->headers->get('ETag'));
});

test('response only exposes whitelisted fields', function () use ($categoriesUrl): void {
    // 對外 API 刻意採最小欄位，鎖死契約避免 image_path（原始儲存路徑）等內部欄位外洩。
    $category = WordwallCategory::factory()->create([
        'image_path' => 'wordwall-categories/secret-path.png',
        'sort' => 1,
    ]);
    $wordwall = Wordwall::factory()->create(['wordwall_category_id' => $category->id]);

    $response = $this->getJsonWithToken($categoriesUrl);

    $response->assertOk();
    $this->assertSame(['id', 'name', 'image_url', 'sort', 'wordwalls'], array_keys($response->json('data.0')));
    $this->assertSame(['id', 'sort', 'resource_url'], array_keys($response->json('data.0.wordwalls.0')));
    $this->assertSame($category->id, $response->json('data.0.id'));
    $this->assertSame($wordwall->id, $response->json('data.0.wordwalls.0.id'));
    $response->assertJsonMissingPath('data.0.image_path');
    $response->assertJsonMissingPath('data.0.created_at');
    $response->assertJsonMissingPath('data.0.wordwalls_count');
    $response->assertJsonMissingPath('data.0.wordwalls.0.wordwall_category_id');
    $response->assertJsonMissingPath('data.0.wordwalls.0.created_at');
});

test('does not trigger n plus one queries', function () use ($categoriesUrl): void {
    // 巢狀清單必須 eager-load wordwalls；查詢數應為常數、不隨分類數線性成長。
    foreach (range(1, 3) as $i) {
        $category = WordwallCategory::factory()->create(['sort' => $i]);
        Wordwall::factory()->count(2)->create(['wordwall_category_id' => $category->id]);
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $response = $this->getJsonWithToken($categoriesUrl);
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    $response->assertOk();
    $response->assertJsonCount(3, 'data');
    // eager load = 2 條（分類 + wordwalls）；N+1 則會是 1 + 分類數。
    $this->assertLessThanOrEqual(3, $queryCount, "查詢數 {$queryCount} 過多，疑似 N+1");
});

test('image url is null when no image', function () use ($categoriesUrl): void {
    WordwallCategory::factory()->create([
        'image_path' => null,
        'sort' => 1,
    ]);

    $response = $this->getJsonWithToken($categoriesUrl);

    $response->assertOk();
    $this->assertNull($response->json('data.0.image_url'));
});

test('nested wordwalls sorted by sort ascending', function () use ($categoriesUrl): void {
    $category = WordwallCategory::factory()->create(['sort' => 1]);
    Wordwall::factory()->create(['wordwall_category_id' => $category->id, 'resource_url' => 'https://wordwall.net/resource/333', 'sort' => 3]);
    Wordwall::factory()->create(['wordwall_category_id' => $category->id, 'resource_url' => 'https://wordwall.net/resource/111', 'sort' => 1]);
    Wordwall::factory()->create(['wordwall_category_id' => $category->id, 'resource_url' => 'https://wordwall.net/resource/222', 'sort' => 2]);

    $response = $this->getJsonWithToken($categoriesUrl);

    $response->assertOk();
    $wordwalls = $response->json('data.0.wordwalls');
    $this->assertSame(1, $wordwalls[0]['sort']);
    $this->assertSame(2, $wordwalls[1]['sort']);
    $this->assertSame(3, $wordwalls[2]['sort']);
    $this->assertSame('https://wordwall.net/resource/111', $wordwalls[0]['resource_url']);
});

test('category without wordwalls returns empty array', function () use ($categoriesUrl): void {
    WordwallCategory::factory()->create();

    $response = $this->getJsonWithToken($categoriesUrl);

    $response->assertOk();
    $this->assertSame([], $response->json('data.0.wordwalls'));
});

test('uncategorized wordwalls are excluded', function () use ($categoriesUrl): void {
    // 沒有分類的 Wordwall 不應出現在任何分類底下（App 因此不會顯示它）。
    $category = WordwallCategory::factory()->create();
    Wordwall::factory()->create(['wordwall_category_id' => $category->id]);
    Wordwall::factory()->create(['wordwall_category_id' => null]);

    $response = $this->getJsonWithToken($categoriesUrl);

    $response->assertOk();
    $response->assertJsonCount(1, 'data.0.wordwalls');
});

test('includes etag header on success', function () use ($categoriesUrl): void {
    WordwallCategory::factory()->create();

    $response = $this->getJsonWithToken($categoriesUrl);

    $response->assertOk();
    $this->assertNotNull(
        $response->headers->get('ETag'),
        '成功回應應帶 ETag header 供 client 做條件式請求'
    );
});

test('returns 304 when if none match matches current list', function () use ($categoriesUrl): void {
    WordwallCategory::factory()->create(['sort' => 1]);

    $first = $this->getJsonWithToken($categoriesUrl);
    $first->assertOk();
    $etag = $first->headers->get('ETag');
    $this->assertNotNull($etag);

    $second = $this->withHeaders([
        'Authorization' => 'Bearer stub-bearer-token',
        'If-None-Match' => $etag,
    ])->getJson($categoriesUrl);

    $second->assertStatus(304);
    $this->assertSame('', $second->getContent());
});

test('returns 200 with new etag when list changes', function () use ($categoriesUrl): void {
    $category = WordwallCategory::factory()->create(['sort' => 1]);

    $first = $this->getJsonWithToken($categoriesUrl);
    $first->assertOk();
    $oldEtag = $first->headers->get('ETag');
    $this->assertNotNull($oldEtag);

    // 在分類底下新增一個遊戲 → body 改變 → ETag 應隨之改變。
    Wordwall::factory()->create(['wordwall_category_id' => $category->id]);

    $second = $this->withHeaders([
        'Authorization' => 'Bearer stub-bearer-token',
        'If-None-Match' => $oldEtag,
    ])->getJson($categoriesUrl);

    $second->assertOk();
    $second->assertJsonCount(1, 'data.0.wordwalls');
    $this->assertNotSame($oldEtag, $second->headers->get('ETag'));
});

test('rate limit blocks after 60 requests', function () use ($categoriesUrl): void {
    for ($i = 1; $i <= 60; $i++) {
        $response = $this->getJsonWithToken($categoriesUrl);
        $this->assertNotSame(
            429,
            $response->status(),
            "Category request #{$i} 不應被限流，實際狀態 {$response->status()}"
        );
    }

    $response = $this->getJsonWithToken($categoriesUrl);
    $response->assertStatus(429);
    $response->assertJsonPath('error.code', 'RATE_LIMITED');
    $this->assertNotNull($response->headers->get('Retry-After'));
});

test('rate limit is independent from wordwalls endpoint', function () use ($categoriesUrl): void {
    // 兩端點各自獨立計數：打滿 categories 60 次後，wordwalls 仍不受影響。
    for ($i = 1; $i <= 60; $i++) {
        $this->getJsonWithToken($categoriesUrl);
    }

    $this->getJsonWithToken($categoriesUrl)->assertStatus(429);
    $this->getJsonWithToken('/api/v1/wordwalls')->assertOk();
});
