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
        $wordwalls = Wordwall::orderBy('sort')->get();

        return WordwallResource::collection($wordwalls)
            ->additional(['meta' => ['total' => $wordwalls->count()]]);
    }
}
