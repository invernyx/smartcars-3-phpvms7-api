<?php

namespace Modules\SmartCARS3phpVMS7Api\Support;

use App\Repositories\SettingRepository;

/**
 * The auto-update settings, read through SettingRepository instead of setting(): setting() caches
 * values for a day in production, which would keep a switched-off updater running and compare
 * against a stale installed commit. (The repository itself only caches when phpVMS's CACHE_ENABLED
 * is on, off by default.)
 */
class AutoUpdateSettings
{
    public const ENABLED = 'smartcars.auto_update';
    public const COMMIT = 'smartcars.auto_update_commit';
    public const STATUS = 'smartcars.auto_update_status';
    // The settings value column may be a 255-character string, which a strict database would refuse to overflow.
    private const MAX_STATUS_LENGTH = 250;

    public static function isEnabled(): bool
    {
        return self::read(self::ENABLED, false) === true;
    }

    public static function installedCommit(): string
    {
        return (string) self::read(self::COMMIT, '');
    }

    public static function saveCommit(string $sha): void
    {
        app(SettingRepository::class)->store(self::COMMIT, $sha);
    }

    public static function saveStatus(string $sha, string $outcome): void
    {
        $status = now()->toIso8601String().($sha === '' ? '' : ' '.$sha).' '.$outcome;
        app(SettingRepository::class)->store(self::STATUS, mb_substr($status, 0, self::MAX_STATUS_LENGTH));
    }

    private static function read(string $key, mixed $default): mixed
    {
        try {
            return app(SettingRepository::class)->retrieve($key);
        } catch (\Throwable $e) {
            // The settings migration has not run yet: the updater stays off.
            return $default;
        }
    }
}
