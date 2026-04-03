<?php

namespace App\Services;

use LucaLongo\Licensing\Contracts\LicenseKeyGeneratorContract;
use LucaLongo\Licensing\Models\License;

class LicenseKeyGenerator implements LicenseKeyGeneratorContract
{
    /**
     * 產生 20 字元授權碼（A-Z 大寫 + 0-9 數字）。
     * 排除易混淆字元：O/0, I/1, L, U（避免使用者輸入錯誤）。
     */
    public function generate(?License $license = null): string
    {
        $chars = 'ABCDEFGHJKMNPQRSTVWXYZ23456789';
        $charsLength = strlen($chars);
        $key = '';

        for ($i = 0; $i < 20; $i++) {
            $key .= $chars[random_int(0, $charsLength - 1)];
        }

        return $key;
    }
}
