<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WordwallResource extends JsonResource
{
    /**
     * @return array{sort: int, resource_url: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'sort' => $this->sort,
            'resource_url' => $this->resource_url,
        ];
    }
}
