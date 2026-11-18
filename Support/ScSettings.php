<?php

namespace Modules\SmartCARS3phpVMS7Api\Support;

use App\Models\Aircraft;
use App\Models\Airline;
use App\Models\Enums\AircraftStatus;

/**
 * The module's VA settings, read with the upstream behavior as the fallback.
 */
class ScSettings
{
    public const AIRLINE_CODE = 'smartcars.airline_code';
    public const AIRCRAFT_NAME = 'smartcars.aircraft_name';
    public const CANCEL_MODE = 'smartcars.cancel_mode';

    public const AIRLINE_CODE_IATA = 'iata';
    public const AIRLINE_CODE_ICAO = 'icao';
    public const AIRCRAFT_NAME_DETAILED = 'detailed';
    public const AIRCRAFT_NAME_PLAIN = 'plain';
    public const CANCEL_MODE_CANCEL = 'cancel';
    public const CANCEL_MODE_DELETE = 'delete';

    public static function airlineCode(Airline $airline): string
    {
        if (setting(self::AIRLINE_CODE, self::AIRLINE_CODE_IATA) === self::AIRLINE_CODE_ICAO) {
            return $airline->icao;
        }

        return $airline->code;
    }

    public static function aircraftName(Aircraft $aircraft): string
    {
        if (setting(self::AIRCRAFT_NAME, self::AIRCRAFT_NAME_DETAILED) === self::AIRCRAFT_NAME_PLAIN) {
            return $aircraft->name;
        }

        return "{$aircraft->name} ({$aircraft->registration}) | ".AircraftStatus::label($aircraft->status);
    }

    public static function isCancelDelete(): bool
    {
        return setting(self::CANCEL_MODE, self::CANCEL_MODE_CANCEL) === self::CANCEL_MODE_DELETE;
    }
}
