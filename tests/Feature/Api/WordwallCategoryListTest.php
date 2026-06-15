<?php

namespace Tests\Feature\Api;

use App\Models\Wordwall;
use App\Models\WordwallCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LucaLongo\Licensing\Contracts\TokenVerifier;
use Mockery;
use RuntimeException;
use Tests\Concerns\MocksLicenseTokenVerifier;
use Tests\TestCase;

/**
 * Wordwall Category List API feature 測試。
 *
 * 端點：GET /api/v1/wordwall-categories
 * 保護：throttle:api-wordwall + VerifyBearerToken (PASETO bearer token 簽章驗證)
 * 回應：巢狀 {data:[{name, image_url, sort, wordwalls:[{sort, resource_url}]}], meta:{total}}
 */
class WordwallCategoryListTest extends TestCase
{
    use MocksLicenseTokenVerifier;
    use RefreshDatabase;

    private const CATEGORIES_URL = '/api/v1/wordwall-categories';

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        // 模擬生產環境的 S3 相容 disk（有 url 設定），讓 image_url 產生絕對 CDN 網址。
        config(['filesystems.default' => 's3']);
        Storage::fake('s3', ['url' => 'https://cdn.test/wordwall']);
        $this->acceptAnyBearerToken();
    }

    public function test_rejects_without_authorization_header(): void
    {
        $response = $this->getJson(self::CATEGORIES_URL);

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
            ->getJson(self::CATEGORIES_URL);

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'INVALID_TOKEN');
    }

    public function test_returns_empty_list_when_no_categories(): void
    {
        $response = $this->getJsonWithToken(self::CATEGORIES_URL);

        $response->assertOk();
        $response->assertJsonPath('data', []);
        $response->assertJsonPath('meta.total', 0);
    }

    public function test_returns_categories_sorted_by_sort_ascending(): void
    {
        WordwallCategory::factory()->create(['name' => '分類C', 'sort' => 3]);
        WordwallCategory::factory()->create(['name' => '分類A', 'sort' => 1]);
        WordwallCategory::factory()->create(['name' => '分類B', 'sort' => 2]);

        $response = $this->getJsonWithToken(self::CATEGORIES_URL);

        $response->assertOk();
        $response->assertJsonCount(3, 'data');
        $response->assertJsonPath('meta.total', 3);

        $data = $response->json('data');
        $this->assertSame('分類A', $data[0]['name']);
        $this->assertSame('分類B', $data[1]['name']);
        $this->assertSame('分類C', $data[2]['name']);
    }

    public function test_response_contains_expected_nested_structure(): void
    {
        $category = WordwallCategory::factory()->create();
        Wordwall::factory()->create(['wordwall_category_id' => $category->id]);

        $response = $this->getJsonWithToken(self::CATEGORIES_URL);

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
    }

    public function test_image_url_is_full_url_built_from_default_disk(): void
    {
        WordwallCategory::factory()->create([
            'image_path' => 'wordwall-categories/math.png',
            'sort' => 1,
        ]);

        $response = $this->getJsonWithToken(self::CATEGORIES_URL);

        $response->assertOk();
        $this->assertSame(
            'https://cdn.test/wordwall/wordwall-categories/math.png',
            $response->json('data.0.image_url')
        );
    }

    public function test_image_url_is_absolute_even_on_disk_returning_relative_path(): void
    {
        // local disk 的 Storage::url() 回傳相對路徑 /storage/...，accessor 應補成絕對網址，
        // 確保原生 App 永遠拿到完整可解析的 URL。
        config(['filesystems.default' => 'local']);

        WordwallCategory::factory()->create([
            'image_path' => 'wordwall-categories/math.png',
            'sort' => 1,
        ]);

        $response = $this->getJsonWithToken(self::CATEGORIES_URL);

        $response->assertOk();
        $imageUrl = $response->json('data.0.image_url');
        $this->assertStringStartsWith('http', $imageUrl);
        $this->assertStringEndsWith('/storage/wordwall-categories/math.png', $imageUrl);
    }

    public function test_response_only_exposes_whitelisted_fields(): void
    {
        // 對外 API 刻意採最小欄位，鎖死契約避免 image_path（原始儲存路徑）等內部欄位外洩。
        $category = WordwallCategory::factory()->create([
            'image_path' => 'wordwall-categories/secret-path.png',
            'sort' => 1,
        ]);
        $wordwall = Wordwall::factory()->create(['wordwall_category_id' => $category->id]);

        $response = $this->getJsonWithToken(self::CATEGORIES_URL);

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
    }

    public function test_does_not_trigger_n_plus_one_queries(): void
    {
        // 巢狀清單必須 eager-load wordwalls；查詢數應為常數、不隨分類數線性成長。
        foreach (range(1, 3) as $i) {
            $category = WordwallCategory::factory()->create(['sort' => $i]);
            Wordwall::factory()->count(2)->create(['wordwall_category_id' => $category->id]);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->getJsonWithToken(self::CATEGORIES_URL);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk();
        $response->assertJsonCount(3, 'data');
        // eager load = 2 條（分類 + wordwalls）；N+1 則會是 1 + 分類數。
        $this->assertLessThanOrEqual(3, $queryCount, "查詢數 {$queryCount} 過多，疑似 N+1");
    }

    public function test_image_url_is_null_when_no_image(): void
    {
        WordwallCategory::factory()->create([
            'image_path' => null,
            'sort' => 1,
        ]);

        $response = $this->getJsonWithToken(self::CATEGORIES_URL);

        $response->assertOk();
        $this->assertNull($response->json('data.0.image_url'));
    }

    public function test_nested_wordwalls_sorted_by_sort_ascending(): void
    {
        $category = WordwallCategory::factory()->create(['sort' => 1]);
        Wordwall::factory()->create(['wordwall_category_id' => $category->id, 'resource_url' => 'https://wordwall.net/resource/333', 'sort' => 3]);
        Wordwall::factory()->create(['wordwall_category_id' => $category->id, 'resource_url' => 'https://wordwall.net/resource/111', 'sort' => 1]);
        Wordwall::factory()->create(['wordwall_category_id' => $category->id, 'resource_url' => 'https://wordwall.net/resource/222', 'sort' => 2]);

        $response = $this->getJsonWithToken(self::CATEGORIES_URL);

        $response->assertOk();
        $wordwalls = $response->json('data.0.wordwalls');
        $this->assertSame(1, $wordwalls[0]['sort']);
        $this->assertSame(2, $wordwalls[1]['sort']);
        $this->assertSame(3, $wordwalls[2]['sort']);
        $this->assertSame('https://wordwall.net/resource/111', $wordwalls[0]['resource_url']);
    }

    public function test_category_without_wordwalls_returns_empty_array(): void
    {
        WordwallCategory::factory()->create();

        $response = $this->getJsonWithToken(self::CATEGORIES_URL);

        $response->assertOk();
        $this->assertSame([], $response->json('data.0.wordwalls'));
    }

    public function test_uncategorized_wordwalls_are_excluded(): void
    {
        // 沒有分類的 Wordwall 不應出現在任何分類底下（App 因此不會顯示它）。
        $category = WordwallCategory::factory()->create();
        Wordwall::factory()->create(['wordwall_category_id' => $category->id]);
        Wordwall::factory()->create(['wordwall_category_id' => null]);

        $response = $this->getJsonWithToken(self::CATEGORIES_URL);

        $response->assertOk();
        $response->assertJsonCount(1, 'data.0.wordwalls');
    }

    public function test_includes_etag_header_on_success(): void
    {
        WordwallCategory::factory()->create();

        $response = $this->getJsonWithToken(self::CATEGORIES_URL);

        $response->assertOk();
        $this->assertNotNull(
            $response->headers->get('ETag'),
            '成功回應應帶 ETag header 供 client 做條件式請求'
        );
    }

    public function test_returns_304_when_if_none_match_matches_current_list(): void
    {
        WordwallCategory::factory()->create(['sort' => 1]);

        $first = $this->getJsonWithToken(self::CATEGORIES_URL);
        $first->assertOk();
        $etag = $first->headers->get('ETag');
        $this->assertNotNull($etag);

        $second = $this->withHeaders([
            'Authorization' => 'Bearer stub-bearer-token',
            'If-None-Match' => $etag,
        ])->getJson(self::CATEGORIES_URL);

        $second->assertStatus(304);
        $this->assertSame('', $second->getContent());
    }

    public function test_returns_200_with_new_etag_when_list_changes(): void
    {
        $category = WordwallCategory::factory()->create(['sort' => 1]);

        $first = $this->getJsonWithToken(self::CATEGORIES_URL);
        $first->assertOk();
        $oldEtag = $first->headers->get('ETag');
        $this->assertNotNull($oldEtag);

        // 在分類底下新增一個遊戲 → body 改變 → ETag 應隨之改變。
        Wordwall::factory()->create(['wordwall_category_id' => $category->id]);

        $second = $this->withHeaders([
            'Authorization' => 'Bearer stub-bearer-token',
            'If-None-Match' => $oldEtag,
        ])->getJson(self::CATEGORIES_URL);

        $second->assertOk();
        $second->assertJsonCount(1, 'data.0.wordwalls');
        $this->assertNotSame($oldEtag, $second->headers->get('ETag'));
    }

    public function test_rate_limit_blocks_after_60_requests(): void
    {
        for ($i = 1; $i <= 60; $i++) {
            $response = $this->getJsonWithToken(self::CATEGORIES_URL);
            $this->assertNotSame(
                429,
                $response->status(),
                "Category request #{$i} 不應被限流，實際狀態 {$response->status()}"
            );
        }

        $response = $this->getJsonWithToken(self::CATEGORIES_URL);
        $response->assertStatus(429);
        $response->assertJsonPath('error.code', 'RATE_LIMITED');
        $this->assertNotNull($response->headers->get('Retry-After'));
    }

    public function test_rate_limit_is_independent_from_wordwalls_endpoint(): void
    {
        // 兩端點各自獨立計數：打滿 categories 60 次後，wordwalls 仍不受影響。
        for ($i = 1; $i <= 60; $i++) {
            $this->getJsonWithToken(self::CATEGORIES_URL);
        }

        $this->getJsonWithToken(self::CATEGORIES_URL)->assertStatus(429);
        $this->getJsonWithToken('/api/v1/wordwalls')->assertOk();
    }
}
