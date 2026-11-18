<?php

namespace Modules\SmartCARS3phpVMS7Api\Support;

/**
 * Where the auto-update reads its releases and downloads them from.
 */
class AutoUpdateSource
{
    public const REPOSITORY = 'invernyx/smartcars-3-phpvms7-api';

    public static function latestReleaseUrl(): string
    {
        return 'https://api.github.com/repos/'.self::REPOSITORY.'/releases/latest';
    }

    public static function commitUrl(string $tag): string
    {
        return 'https://api.github.com/repos/'.self::REPOSITORY.'/commits/'.implode('/', array_map('rawurlencode', explode('/', $tag)));
    }

    public static function archiveUrl(string $sha): string
    {
        return 'https://codeload.github.com/'.self::REPOSITORY.'/zip/'.$sha;
    }
}
