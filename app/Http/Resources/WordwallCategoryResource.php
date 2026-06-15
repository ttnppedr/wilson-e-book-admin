<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class WordwallCategoryResource extends JsonResource
{
    /**
     * @return array{id: int, name: string, image_url: string|null, sort: int, wordwalls: AnonymousResourceCollection}
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'image_url' => $this->image_url,
            'sort' => $this->sort,
            'wordwalls' => WordwallResource::collection($this->whenLoaded('wordwalls')),
        ];
    }
}
