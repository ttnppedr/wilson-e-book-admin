<?php

/**
 * Wordwall Category 圖片轉址端點 feature 測試。
 *
 * 端點：GET /api/v1/wordwall-categories/{wordwallCategory}/image
 * 保護：throttle:api-wordwall + VerifyBearerToken (PASETO bearer token 簽章驗證)
 * 行為：S3 物件維持 private，於請求當下即時簽發 presigned 短效網址並 302 轉址。
 */

use App\Models\WordwallCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use LucaLongo\Licensing\Contracts\TokenVerifier;
use Tests\Concerns\MocksLicenseTokenVerifier;

uses(MocksLicenseTokenVerifier::class, RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    config(['filesystems.default' => 's3']);
    Storage::fake('s3');
    // 本機 / fake S3 adapter 預設不支援 temporaryUrl，註冊 stub callback 讓簽發可被斷言。
    Storage::disk('s3')->buildTemporaryUrlsUsing(
        fn (string $path, $expiration, array $options = []) => 'https://s3.test/signed/'.$path.'?sig=stub'
    );
    $this->acceptAnyBearerToken();
});

$imageUrl = function (WordwallCategory $category): string {
    return '/api/v1/wordwall-categories/'.$category->id.'/image';
};

test('rejects without authorization header', function () use ($imageUrl): void {
    $category = WordwallCategory::factory()->create();

    $response = $this->getJson($imageUrl($category));

    $response->assertStatus(401);
    $response->assertJsonPath('error.code', 'INVALID_TOKEN');
});

test('rejects with invalid token', function () use ($imageUrl): void {
    $verifier = Mockery::mock(TokenVerifier::class);
    $verifier->shouldReceive('verify')
        ->once()
        ->andThrow(new RuntimeException('paseto parse failed'));
    $this->app->instance(TokenVerifier::class, $verifier);

    $category = WordwallCategory::factory()->create();

    $response = $this->withHeaders(['Authorization' => 'Bearer bad-token'])
        ->getJson($imageUrl($category));

    $response->assertStatus(401);
    $response->assertJsonPath('error.code', 'INVALID_TOKEN');
});

test('redirects to freshly signed temporary url', function () use ($imageUrl): void {
    $category = WordwallCategory::factory()->create([
        'image_path' => 'wordwall-categories/math.png',
    ]);

    $response = $this->withHeaders(['Authorization' => 'Bearer stub-bearer-token'])
        ->get($imageUrl($category));

    $response->assertRedirect('https://s3.test/signed/wordwall-categories/math.png?sig=stub');
});

test('returns unified 404 when category has no image', function () use ($imageUrl): void {
    $category = WordwallCategory::factory()->create(['image_path' => null]);

    $response = $this->withHeaders(['Authorization' => 'Bearer stub-bearer-token'])
        ->getJson($imageUrl($category));

    $response->assertStatus(404);
    $response->assertJsonPath('error.code', 'IMAGE_NOT_FOUND');
});

test('returns 404 for unknown category', function (): void {
    $response = $this->withHeaders(['Authorization' => 'Bearer stub-bearer-token'])
        ->getJson('/api/v1/wordwall-categories/999999/image');

    $response->assertStatus(404);
});
