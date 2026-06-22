<?php

namespace App\Http\Controllers\Api;

use App\Models\WordwallCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;

class WordwallCategoryImageController extends Controller
{
    /**
     * 即時簽發分類圖片的 S3 presigned 短效網址並 302 轉址。
     *
     * S3 物件全程維持 private：清單端點回傳的 image_url 指向此穩定端點（不含簽章，
     * 故清單的內容型 ETag 不受每次請求簽章/時間戳變動的污染），實際的限時存取憑證
     * 在此於請求當下即時簽發。端點本身受 VerifyBearerToken 保護，僅持有合法 PASETO
     * token 的 App 能換取圖片網址。
     */
    public function __invoke(WordwallCategory $wordwallCategory): RedirectResponse|JsonResponse
    {
        if ($wordwallCategory->image_path === null) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'IMAGE_NOT_FOUND',
                    'message' => 'Category has no image',
                ],
            ], 404);
        }

        $temporaryUrl = Storage::disk(config('filesystems.default'))
            ->temporaryUrl($wordwallCategory->image_path, now()->addMinutes(15));

        return redirect()->away($temporaryUrl);
    }
}
