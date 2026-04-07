<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContentEncryptionKey extends Model
{
    protected $fillable = [
        'name',
        'encrypted_key',
    ];

    protected $hidden = [
        'encrypted_key',
    ];

    protected function casts(): array
    {
        return [
            'encrypted_key' => 'encrypted',
        ];
    }

    public function scopes(): HasMany
    {
        return $this->hasMany(LicenseScope::class);
    }

    /**
     * 產生純英數金鑰（43 字元，約 256 bits 熵）。
     */
    public static function generateKey(): string
    {
        return substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(48))), 0, 43);
    }
}
