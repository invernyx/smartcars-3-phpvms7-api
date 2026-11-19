<?php

namespace Modules\SmartCARS3phpVMS7Api\Support;

use Illuminate\Support\Facades\File;

class NudgeCooldown
{
    public const COOLDOWN_SEC = 3600;

    public static function isActive(): bool
    {
        try {
            $content = trim(File::get(self::file()));
        } catch (\Throwable $e) {
            // A missing or unreadable file means no nudge was recorded: no cooldown.
            return false;
        }
        if (!ctype_digit($content)) {
            return false;
        }
        return time() - (int) $content < self::COOLDOWN_SEC;
    }

    public static function start(): void
    {
        File::ensureDirectoryExists(dirname(self::file()));
        File::put(self::file(), (string) time());
    }

    private static function file(): string
    {
        return storage_path('app/smartcars-auto-update/last-nudge');
    }
}
