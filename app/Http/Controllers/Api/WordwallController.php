<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\WordwallResource;
use App\Models\Wordwall;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Routing\Controller;

class WordwallController extends Controller
{
    public function __invoke(Request $request): ResourceCollection
    {
        // 以 id 作為次要排序鍵，確保 sort 碰撞時回傳順序穩定（ETag/304 才不會無謂失效）。
        $wordwalls = Wordwall::orderBy('sort')->orderBy('id')->get();

        return WordwallResource::collection($wordwalls)
            ->additional(['meta' => ['total' => $wordwalls->count()]]);
    }
}
