<?php

namespace Modules\SmartCARS3phpVMS7Api\Support;

use App\Models\Aircraft;
use App\Models\Airline;
use App\Models\Airport;
use App\Models\Enums\AircraftState;
use App\Models\Enums\AircraftStatus;
use App\Models\Subfleet;

/**
 * The unknown airport and aircraft a free flight files against when it has no real one.
 * They stay when free flights are switched off, since PIREPs reference them.
 */
class FreeFlightPlaceholders
{
    public const AIRPORT_ID = 'XXXX';
    public const AIRPORT_NAME = 'Unknown / off-field';
    public const SUBFLEET_TYPE = 'SCFREE';
    public const SUBFLEET_NAME = 'smartCARS free flights';
    public const AIRCRAFT_REGISTRATION = 'SC-FREE';
    public const AIRCRAFT_NAME = 'Unknown aircraft';
    public const AIRCRAFT_ICAO = 'ZZZZ';

    private static ?int $aircraftId = null;
    private static bool $isAircraftLookedUp = false;

    public static function ensure(): void
    {
        // withTrashed: a soft-deleted row still holds the primary key or the unique registration
        $airport = Airport::withTrashed()->firstOrCreate(
            ['id' => self::AIRPORT_ID],
            ['icao' => self::AIRPORT_ID, 'name' => self::AIRPORT_NAME, 'lat' => 0, 'lon' => 0]
        );
        if ($airport->trashed()) {
            $airport->restore();
        }

        $aircraft = Aircraft::withTrashed()->where('registration', self::AIRCRAFT_REGISTRATION)->first();
        // The fares of a PIREP are read through its aircraft's subfleet, so the aircraft must keep one
        $subfleet = $aircraft !== null ? Subfleet::withTrashed()->find($aircraft->subfleet_id) : null;
        // A subfleet an admin moved the aircraft into is theirs: it is never restored
        if ($subfleet === null || ($subfleet->trashed() && $subfleet->type !== self::SUBFLEET_TYPE)) {
            $subfleet = Subfleet::withTrashed()->firstOrCreate(
                ['type' => self::SUBFLEET_TYPE],
                ['name' => self::SUBFLEET_NAME, 'airline_id' => Airline::first()?->id]
            );
        }
        if ($subfleet->trashed()) {
            $subfleet->restore();
        }
        if ($aircraft !== null && (int) $aircraft->subfleet_id !== (int) $subfleet->id) {
            $aircraft->subfleet_id = $subfleet->id;
            $aircraft->save();
        }
        if ($aircraft === null) {
            $aircraft = Aircraft::withTrashed()->firstOrCreate(
                ['registration' => self::AIRCRAFT_REGISTRATION],
                [
                    'subfleet_id' => $subfleet->id,
                    'name'        => self::AIRCRAFT_NAME,
                    'icao'        => self::AIRCRAFT_ICAO,
                    'state'       => AircraftState::PARKED,
                    'status'      => AircraftStatus::ACTIVE,
                ]
            );
        }
        if ($aircraft->trashed()) {
            $aircraft->restore();
        }
        self::$aircraftId = (int) $aircraft->id;
        self::$isAircraftLookedUp = true;
    }

    public static function aircraftId(): int
    {
        if (self::$aircraftId === null) {
            self::ensure();
        }

        return self::$aircraftId;
    }

    /**
     * Never creates the placeholders, so it is safe while free flights are off
     */
    public static function existingAircraftId(): ?int
    {
        if (!self::$isAircraftLookedUp) {
            $existingId = Aircraft::withTrashed()->where('registration', self::AIRCRAFT_REGISTRATION)->value('id');
            self::$aircraftId = $existingId === null ? null : (int) $existingId;
            self::$isAircraftLookedUp = true;
        }

        return self::$aircraftId;
    }

    public static function isPlaceholderAircraft(int $id): bool
    {
        return self::existingAircraftId() === $id;
    }
}
