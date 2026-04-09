<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum LicenseStatusLabel: string implements HasColor, HasLabel
{
    case Pending = 'pending';
    case Active = 'active';
    case Expired = 'expired';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => '待啟用',
            self::Active => '使用中',
            self::Expired => '已到期',
            self::Suspended => '已暫停',
            self::Cancelled => '已取消',
        };
    }

    public function getColor(): string|array|null
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Active => 'success',
            default => 'danger',
        };
    }
}
