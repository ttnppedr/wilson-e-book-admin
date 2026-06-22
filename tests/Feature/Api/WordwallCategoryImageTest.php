<?php

namespace Tests\Feature\Api;

use App\Models\WordwallCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use LucaLongo\Licensing\Contracts\TokenVerifier;
use Mockery;
use RuntimeException;
use Tests\Concerns\MocksLicenseTokenVerifier;
use Tests\TestCase;

/**
 * Wordwall Category 圖片轉址端點 feature 測試。
 *
 * 端點：GET /api/v1/wordwall-categories/{wordwallCategory}/image
 * 保護：throttle:api-wordwall + VerifyBearerToken (PASETO bearer token 簽章驗證)
 * 行為：S3 物件維持 private，於請求當下即時簽發 presigned 短效網址並 302 轉址。
 */
class WordwallCategoryImageTest extends TestCase
{
    use MocksLicenseTokenVerifier;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config(['filesystems.default' => 's3']);
        Storage::fake('s3');
        // 本機 / fake S3 adapter 預設不支援 temporaryUrl，註冊 stub callback 讓簽發可被斷言。
        Storage::disk('s3')->buildTemporaryUrlsUsing(
            fn (string $path, $expiration, array $options = []) => 'https://s3.test/signed/'.$path.'?sig=stub'
        );
        $this->acceptAnyBearerToken();
    }

    private function imageUrl(WordwallCategory $category): string
    {
        return '/api/v1/wordwall-categories/'.$category->id.'/image';
    }

    public function test_rejects_without_authorization_header(): void
    {
        $category = WordwallCategory::factory()->create();

        $response = $this->getJson($this->imageUrl($category));

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

        $category = WordwallCategory::factory()->create();

        $response = $this->withHeaders(['Authorization' => 'Bearer bad-token'])
            ->getJson($this->imageUrl($category));

        $response->assertStatus(401);
        $response->assertJsonPath('error.code', 'INVALID_TOKEN');
    }

    public function test_redirects_to_freshly_signed_temporary_url(): void
    {
        $category = WordwallCategory::factory()->create([
            'image_path' => 'wordwall-categories/math.png',
        ]);

        $response = $this->withHeaders(['Authorization' => 'Bearer stub-bearer-token'])
            ->get($this->imageUrl($category));

        $response->assertRedirect('https://s3.test/signed/wordwall-categories/math.png?sig=stub');
    }

    public function test_returns_unified_404_when_category_has_no_image(): void
    {
        $category = WordwallCategory::factory()->create(['image_path' => null]);

        $response = $this->withHeaders(['Authorization' => 'Bearer stub-bearer-token'])
            ->getJson($this->imageUrl($category));

        $response->assertStatus(404);
        $response->assertJsonPath('error.code', 'IMAGE_NOT_FOUND');
    }

    public function test_returns_404_for_unknown_category(): void
    {
        $response = $this->withHeaders(['Authorization' => 'Bearer stub-bearer-token'])
            ->getJson('/api/v1/wordwall-categories/999999/image');

        $response->assertStatus(404);
    }
}
