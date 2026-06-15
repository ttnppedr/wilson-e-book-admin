<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\WordwallCategoryResource;
use App\Models\WordwallCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Routing\Controller;

class WordwallCategoryController extends Controller
{
    /**
     * 回傳所有遊戲分類，每個分類巢狀帶出其底下（依 sort 排序）的 Wordwall 遊戲清單。
     * 供 App 遊戲頁的兩層導覽（分類 → 該分類的遊戲連結）使用。
     */
    public function __invoke(Request $request): ResourceCollection
    {
        // 以 id 作為次要排序鍵：sort 無唯一限制，碰撞時若無 tie-break，DB 回傳順序不保證穩定，
        // 會讓相同資料序列化出不同 body → md5 ETag 改變、304 失效。
        $categories = WordwallCategory::query()
            ->with(['wordwalls' => fn ($query) => $query->orderBy('sort')->orderBy('id')])
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        return WordwallCategoryResource::collection($categories)
            ->additional(['meta' => ['total' => $categories->count()]]);
    }
}
