<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LucaLongo\Licensing\Models\LicenseTemplate;

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

    public function templates(): HasMany
    {
        return $this->hasMany(LicenseTemplate::class);
    }

    /**
     * 產生純英數金鑰（43 字元，約 256 bits 熵）。
     */
    public static function generateKey(): string
    {
        return substr(str_replace(['+', '/', '='], '', base64_encode(random_bytes(48))), 0, 43);
    }
}
