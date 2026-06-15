<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WordwallResource extends JsonResource
{
    /**
     * @return array{id: int, sort: int, resource_url: string}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sort' => $this->sort,
            'resource_url' => $this->resource_url,
        ];
    }
}
