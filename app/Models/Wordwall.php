<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Wordwall extends Model
{
    protected $fillable = [
        'resource_url',
        'sort',
    ];

    protected function casts(): array
    {
        return [
            'sort' => 'integer',
        ];
    }
}
