<?php

namespace Modules\SmartCARS3phpVMS7Api\Http\Controllers\Api;

use App\Contracts\Controller;
use App\Events\PirepPrefiled;
use App\Exceptions\AbstractHttpException;
use App\Exceptions\AircraftNotAtAirport;
use App\Exceptions\AircraftNotAvailable;
use App\Exceptions\AircraftPermissionDenied;
use App\Exceptions\AirportNotFound;
use App\Exceptions\BidExistsForAircraft;
use App\Exceptions\BidExistsForFlight;
use App\Exceptions\BidNotFound;
use App\Exceptions\UserBidLimit;
use App\Exceptions\UserNotAtAirport;
use App\Models\Acars;
use App\Models\Aircraft;
use App\Models\Airline;
use App\Models\Airport;
use App\Models\Bid;
use App\Models\Enums\AcarsType;
use App\Models\Enums\AircraftState;
use App\Models\Enums\AircraftStatus;
use App\Models\Enums\Days;
use App\Models\Enums\FareType;
use App\Models\Enums\FlightType;
use App\Models\Enums\PirepFieldSource;
use App\Models\Enums\PirepSource;
use App\Models\Enums\PirepState;
use App\Models\Enums\PirepStatus;
use App\Models\Fare;
use App\Models\Flight;
use App\Models\Pirep;
use App\Models\PirepFare;
use App\Models\PirepFieldValue;
use App\Models\SimBrief;
use App\Models\User;
use App\Services\BidService;
use App\Services\FareService;
use App\Services\FlightService;
use App\Services\PirepService;
use App\Services\UserService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\SmartCARS3phpVMS7Api\Actions\PirepDistanceCalculation;
use Modules\SmartCARS3phpVMS7Api\Models\FlightAnalysis;
use Modules\SmartCARS3phpVMS7Api\Models\PirepLog;
use Modules\SmartCARS3phpVMS7Api\Providers\AppServiceProvider;
use Modules\SmartCARS3phpVMS7Api\Support\FieldExtensions;
use Modules\SmartCARS3phpVMS7Api\Support\ScSettings;

/**
 * class ApiController
 * @package Modules\SmartCARS3phpVMS7Api\Http\Controllers\Api
 */
class FlightsController extends Controller
{
    private const SEARCH_LIMIT = 250;
    private const ARRIVAL_SEARCH_LIMIT = 250;
    private const DESTINATION_ROUTES_LIMIT = 2000;
    private const ROUTE_TIE_BREAK_SQL = 'flights.dpt_airport_id, flights.arr_airport_id, flights.id';
    private const NEXT_CURSOR_HEADER = 'X-SmartCARS-Next-Cursor';
    private const TOTAL_FLIGHTS_HEADER = 'X-SmartCARS-Total-Flights';
    private const TOTAL_ROUTES_HEADER = 'X-SmartCARS-Total-Routes';
    private const INVALID_CURSOR_MESSAGE = 'Invalid cursor';
    private const DIVERSION_FIELD_SLUG = 'diversion-airport';
    private const NETWORK_FIELD_SLUG = 'network';
    private const NETWORK_FIELD_NAME = 'Network';
    private const SIMULATOR_FIELD_SLUG = 'simulator';
    private const SIMULATOR_FIELD_NAME = 'Simulator';
    private const FIELD_VALUE_MAX_LENGTH = 255;
    private const LBS_PER_KG = 2.20462;
    private const DAY_NAMES = [
        Days::MONDAY    => 'Monday',
        Days::TUESDAY   => 'Tuesday',
        Days::WEDNESDAY => 'Wednesday',
        Days::THURSDAY  => 'Thursday',
        Days::FRIDAY    => 'Friday',
        Days::SATURDAY  => 'Saturday',
        Days::SUNDAY    => 'Sunday',
    ];
    private const USER_AGENT_VERSION_PATTERN = '#^smartCARS/(\d+)#';
    private const IATA_FLIGHT_TYPES_MIN_MAJOR = 4;
    private const SOURCE_NAME_PREFIX = 'smartCARS ';
    private const SOURCE_NAME_DEFAULT = 'smartCARS 3';
    private const DEFAULT_CLIENT_MAJOR = 3;
    // acars.source is a VARCHAR(5), too short for the PIREP's source name
    private const ACARS_SOURCE_PREFIX = 'SC';
    private const ACARS_SOURCE_MAX_LENGTH = 5;
    private const AIRBORNE_STATUSES = [
        PirepStatus::TAKEOFF,
        PirepStatus::INIT_CLIM,
        PirepStatus::ENROUTE,
        PirepStatus::APPROACH,
        PirepStatus::APPROACH_ICAO,
        PirepStatus::LANDING,
    ];
    private const FLIGHT_EVENTS_MAX = 100;
    private const FLIGHT_EVENT_TYPES = ['info', 'event', 'comment'];
    private const FLIGHT_EVENT_ID_PREFIX = 'scev';
    private const FLIGHT_EVENT_ID_HASH_LENGTH = 32;
    private const ACARS_LOG_MAX_LENGTH = 255;
    private const ACARS_DECIMAL_MAX = 999999.99;
    private const ACARS_POSITION_DEFAULT = 0.0;
    private const MAX_LATITUDE = 90;
    private const MAX_LONGITUDE = 180;
    private const SIM_TIME_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/D';
    private const SIM_TIME_INPUT_FORMAT = 'Y-m-d\TH:i:s';
    private const SIM_TIME_COLUMN_FORMAT = 'Y-m-d H:i:s';
    private const SIM_TIME_MIN_YEAR = 1;
    private const TRANSPONDER_PATTERN = '/^[0-7]{4}$/D';
    private const AUTOPILOT_ENGAGED = '1';
    private const AUTOPILOT_DISENGAGED = '0';
    private const REFUSAL_CODES = [
        AircraftNotAvailable::class     => 'aircraft_not_available',
        AircraftNotAtAirport::class     => 'aircraft_not_at_airport',
        AircraftPermissionDenied::class => 'aircraft_not_allowed',
        UserNotAtAirport::class         => 'pilot_not_at_airport',
        BidExistsForFlight::class       => 'flight_already_booked',
        BidExistsForAircraft::class     => 'aircraft_already_booked',
        UserBidLimit::class             => 'booking_limit',
        BidNotFound::class              => 'booking_not_found',
    ];
    private const FILED_STATUSES = [
        PirepState::ACCEPTED => 'accepted',
        PirepState::PENDING  => 'pending',
        PirepState::REJECTED => 'rejected',
    ];

    public function __construct(
        public FlightService $flightService,
        public FareService $fareService,
        public BidService $bidService,
        public PirepService $pirepService
    ) {
    }

    /**
     * Just send out a message
     *
     * @param Request $request
     *
     * @return mixed
     */
    public function book(Request $request)
    {
        $flight = Flight::find($request->input('flightID'));
        if ($flight === null) {
            return $this->refusal('Flight not found', 'invalid_request', 404);
        }
        $user = User::find($request->get('pilotID'));
        $aircraftId = $request->input('aircraftID');
        $aircraft = null;
        if ($aircraftId !== null) {
            $aircraft = is_numeric($aircraftId) ? Aircraft::find((int) $aircraftId) : null;
            if ($aircraft === null) {
                return $this->unknownAircraft($aircraftId);
            }
        }
        try {
            // With the aircraft, phpVMS applies its "block aircraft" setting
            $bid = $this->bidService->addBid($flight, $user, $aircraft);
        } catch (AbstractHttpException $e) {
            return $this->exceptionRefusal($e, 409);
        }
        // force setting the aircraft selected for smartCARS bidding
        if ($aircraft !== null) {
            $bid = Bid::find($bid->id);
            $bid->aircraft_id = $aircraft->id;
            $bid->save();
        }
        return response()->json(["bidID" => $bid->id]);
    }
    private function refusal(string $message, string $code, int $status)
    {
        return response()->json(['message' => $message, 'code' => $code], $status);
    }
    private function unknownAircraft($aircraftId)
    {
        return $this->refusal('Aircraft '.$aircraftId.' not found', 'invalid_request', 400);
    }
    private function refusalCode(\Throwable $e): ?string
    {
        foreach (self::REFUSAL_CODES as $class => $code) {
            if ($e instanceof $class) {
                return $code;
            }
        }
        return null;
    }
    private function exceptionRefusal(\Throwable $e, int $status)
    {
        $code = $this->refusalCode($e);
        return $code === null
            ? response()->json(['message' => $e->getMessage()], $status)
            : $this->refusal($e->getMessage(), $code, $status);
    }
    private function clientMajorVersion(Request $request): ?int
    {
        return preg_match(self::USER_AGENT_VERSION_PATTERN, (string) $request->userAgent(), $matches) === 1
            ? (int) $matches[1]
            : null;
    }
    private function sendsIataFlightTypes(Request $request): bool
    {
        $major = $this->clientMajorVersion($request);
        return $major !== null && $major >= self::IATA_FLIGHT_TYPES_MIN_MAJOR;
    }
    private function sourceName(Request $request): string
    {
        $major = $this->clientMajorVersion($request);
        return $major !== null && $major >= self::IATA_FLIGHT_TYPES_MIN_MAJOR
            ? self::SOURCE_NAME_PREFIX.$major
            : self::SOURCE_NAME_DEFAULT;
    }
    private function acarsSource(Request $request): string
    {
        $source = self::ACARS_SOURCE_PREFIX.($this->clientMajorVersion($request) ?? self::DEFAULT_CLIENT_MAJOR);
        return strlen($source) <= self::ACARS_SOURCE_MAX_LENGTH
            ? $source
            : self::ACARS_SOURCE_PREFIX.self::DEFAULT_CLIENT_MAJOR;
    }
    private function outputFlightType(Request $request, $type)
    {
        return $this->sendsIataFlightTypes($request) ? $type : $this->flightType($type);
    }
    public function rebook(Request $request)
    {
        $bid = Bid::where(['id' => $request->input('bidID'), 'user_id' => Auth::id()])->first();
        if ($bid === null) {
            return $this->refusal('Booking not found', 'booking_not_found', 404);
        }
        if ($request->input('aircraftID') !== null && Aircraft::find($request->input('aircraftID')) === null) {
            return $this->unknownAircraft($request->input('aircraftID'));
        }
        // force setting the aircraft selected for smartCARS bidding
        $bid->aircraft_id = $request->input('aircraftID');
        $bid->save();
        return response()->json(["bidID" => $bid->id]);
    }
    public function bookings(Request $request)
    {
        $user = User::find($request->get('pilotID'));
        $bids = $this->bidService->findBidsForUser($user);
        $bids->load('flight', 'flight.subfleets', 'flight.subfleets.aircraft', 'flight.field_values');
        $output = [];

        foreach ($bids as $bid) {
            // The booking's own aircraft (set by booking or rebooking), else the aircraft of the pilot's
            // SimBrief briefing, else every aircraft the pilot may fly
            $aircraft = [];
            $briefedAircraftId = $this->briefedAircraftId($bid->flight, $user);
            if ($bid->aircraft_id !== null) {
                $aircraft = (int) $bid->aircraft_id;
            } elseif ($briefedAircraftId !== null) {
                $aircraft = $briefedAircraftId;
            } else {
                $flight = $this->flightService->filterSubfleets($user, $bid->flight);
                foreach ($flight->subfleets->sortBy('name') as $subfleet) {
                    foreach ($subfleet->aircraft->sortBy('registration') as $acf) {
                        $aircraft[] = $acf['id'];
                    }
                }
            }
            $ft_converted = floatval(number_format($bid->flight->flight_time / 60, 2));

            // If Current Airport Setting is enabled, check if person is at the correct airport before showing the bid.
            if (setting('pilots.only_flights_from_current') && $bid->flight->dpt_airport_id !== $user->curr_airport_id) {
                continue;
            }

            $booking = [
                "bidID"            => $bid->id,
                "number"           => $bid->flight->flight_number,
                "code"             => ScSettings::airlineCode($bid->flight->airline),
                "departureAirport" => $bid->flight->dpt_airport_id,
                "arrivalAirport"   => $bid->flight->arr_airport_id,
                "alternateAirport" => $bid->flight->alt_airport_id ?: null,
                "route"            => $bid->flight->route ? explode(" ", $bid->flight->route) : null,
                "flightLevel"      => $bid->flight->level,
                "distance"         => $bid->flight->distance->getResponseUnits()['nmi'],
                "departureTime"    => $bid->flight->dpt_time,
                "arrivalTime"      => $bid->flight->arr_time,
                "flightTime"       => $ft_converted,
                "daysOfWeek"       => $this->daysOfWeek($bid->flight->days),
                "flightID"         => $bid->flight->id,
                "type"             => $this->outputFlightType($request, $bid->flight->flight_type),
                "aircraft"         => $aircraft,
                "notes"            => $bid->flight->notes
            ];
            $extensions = $this->flightExtensions($bid->flight);
            if ($extensions !== null) {
                $booking['extensions'] = $extensions;
            }
            $output[] = $booking;
        }

        return response()->json($output);
    }
    public function cancel(Request $request)
    {
        $pirep = Pirep::where(['id' => $request->input('uuid'), 'user_id' => Auth::id()])->first();
        if ($pirep === null) {
            return response()->json(['status' => 200]);
        }
        if (!in_array($pirep->state, [PirepState::IN_PROGRESS, PirepState::PAUSED, PirepState::CANCELLED], true)) {
            return $this->refusal('This flight has already been filed', 'flight_already_filed', 409);
        }

        // A CANCELLED one is a retry: phpVMS refuses to cancel it twice
        if ($pirep->state !== PirepState::CANCELLED) {
            $this->pirepService->cancel($pirep);
        }
        if (!ScSettings::isCancelDelete()) {
            return response()->json(['status' => 200]);
        }

        DB::transaction(function () use ($pirep) {
            // Keep flight_id so the OFP stays on the booked flight
            SimBrief::where('pirep_id', $pirep->id)->update(['pirep_id' => null]);
            Acars::where('pirep_id', $pirep->id)->delete();
            PirepLog::where('pirep_id', $pirep->id)->delete();
            FlightAnalysis::where('pirep_id', $pirep->id)->delete();
            $this->pirepService->delete($pirep);
        });

        return response()->json(['status' => 200]);
    }
    public function divert(Request $request)
    {
        $pirep = Pirep::where(['id' => $request->input('uuid'), 'user_id' => Auth::id()])->first();
        if ($pirep === null) {
            return $this->refusal('No active flight', 'no_active_flight', 404);
        }
        if (!in_array($pirep->state, [PirepState::IN_PROGRESS, PirepState::PAUSED], true)) {
            return $this->refusal('This flight is not in progress', 'flight_not_in_progress', 409);
        }
        if (!setting('pireps.handle_diversion', false)) {
            return $this->refusal('Diversion handling is disabled for this VA', 'diversion_disabled', 422);
        }

        $icao = strtoupper(trim((string) $request->input('airport')));
        if ($icao === '' || Airport::find($icao) === null) {
            return $this->refusal('Unknown airport', 'unknown_airport', 422);
        }

        if ($icao === $pirep->arr_airport_id) {
            // The status stays as is: the next update restores the phase
            PirepFieldValue::where(['pirep_id' => $pirep->id, 'slug' => self::DIVERSION_FIELD_SLUG])->delete();
        } else {
            PirepFieldValue::updateOrCreate(
                ['pirep_id' => $pirep->id, 'slug' => self::DIVERSION_FIELD_SLUG],
                ['name' => 'Diversion Airport', 'value' => $icao, 'source' => PirepFieldSource::ACARS]
            );
            $pirep->status = PirepStatus::DIVERTED;
            $pirep->save();
        }

        return response()->json(['status' => 200]);
    }

    public function events(Request $request)
    {
        $pirep = Pirep::where(['id' => $request->input('uuid'), 'user_id' => Auth::id()])->first();
        if ($pirep === null) {
            return $this->refusal('No active flight', 'no_active_flight', 404);
        }
        if (!in_array($pirep->state, [PirepState::IN_PROGRESS, PirepState::PAUSED], true)) {
            return $this->refusal('This flight is not in progress', 'flight_not_in_progress', 409);
        }

        $events = $request->input('events');
        if (!is_array($events) || !array_is_list($events) || count($events) === 0 || count($events) > self::FLIGHT_EVENTS_MAX) {
            return $this->refusal('events must be a non-empty array of at most '.self::FLIGHT_EVENTS_MAX.' entries', 'invalid_request', 422);
        }

        $now = Carbon::now('UTC');
        $source = $this->acarsSource($request);
        $rows = [];
        foreach ($events as $event) {
            $createdAt = is_array($event) ? $this->flightEventTime($event['timestamp'] ?? null) : null;
            $eventId = is_array($event) && is_int($event['index'] ?? null) ? $this->flightEventId($pirep->id, $event['index']) : null;
            if (
                $eventId === null
                || !in_array($event['type'] ?? null, self::FLIGHT_EVENT_TYPES, true)
                // ConvertEmptyStringsToNull turns a blank message into null before it gets here
                || !array_key_exists('message', $event)
                || !(is_string($event['message']) || $event['message'] === null)
                || $createdAt === null
            ) {
                return $this->refusal('Each event needs an integer index >= 0, a type of info, event or comment, a message and a timestamp', 'invalid_request', 422);
            }
            $rows[] = [
                'id'         => $eventId,
                'pirep_id'   => $pirep->id,
                'type'       => AcarsType::LOG,
                'log'        => is_string($event['message']) ? mb_substr($event['message'], 0, self::ACARS_LOG_MAX_LENGTH) : null,
                'created_at' => $createdAt,
                'updated_at' => $now,
                'sim_time'   => $this->simTimeColumn($event['simTime'] ?? null),
                'source'     => $source,
            ] + $this->logPositionColumns($event['position'] ?? null);
        }
        // First write wins: a resent line keeps the row stored the first time
        Acars::insertOrIgnore($rows);

        return response()->json(['status' => 200]);
    }

    private function flightEventId(string $pirepId, $index): ?string
    {
        if (is_string($index) && ctype_digit($index)) {
            $index = (int) $index;
        }
        if (!is_int($index) || $index < 0) {
            return null;
        }
        // acars.id is a 36-character string; the prefix keeps these ids apart from phpVMS's own hash ids and UUIDs
        return self::FLIGHT_EVENT_ID_PREFIX.substr(hash('sha256', $pirepId.':'.$index), 0, self::FLIGHT_EVENT_ID_HASH_LENGTH);
    }

    private function simTimeColumn($simTime): ?string
    {
        $zulu = is_array($simTime) ? ($simTime['zulu'] ?? null) : null;
        if (!is_string($zulu) || preg_match(self::SIM_TIME_PATTERN, $zulu) !== 1) {
            return null;
        }
        $time = \DateTimeImmutable::createFromFormat('!'.self::SIM_TIME_INPUT_FORMAT, $zulu, new \DateTimeZone('UTC'));
        // The round trip rejects dates PHP would roll over, like a 31 April
        if ($time === false || $time->format(self::SIM_TIME_INPUT_FORMAT) !== $zulu || (int) $time->format('Y') < self::SIM_TIME_MIN_YEAR) {
            return null;
        }
        // phpVMS's own ACARS API stores a Carbon here, which the query builder binds in this format
        return $time->format(self::SIM_TIME_COLUMN_FORMAT);
    }

    private function logPositionColumns($position): array
    {
        $latitude = is_array($position) ? $this->finiteNumber($position['latitude'] ?? null) : null;
        $longitude = is_array($position) ? $this->finiteNumber($position['longitude'] ?? null) : null;
        $altitude = is_array($position) ? $this->finiteNumber($position['altitude'] ?? null) : null;
        if (
            $latitude !== null && abs($latitude) <= self::MAX_LATITUDE
            && $longitude !== null && abs($longitude) <= self::MAX_LONGITUDE
            && $altitude !== null && abs($altitude) <= self::ACARS_DECIMAL_MAX
        ) {
            // altitude_msl only: insertOrIgnore skips the Acars altitude mutator, so altitude_agl keeps its default
            return ['lat' => $latitude, 'lon' => $longitude, 'altitude_msl' => $altitude];
        }
        // insertOrIgnore takes its columns from the first row, so every row carries them, with the columns' own defaults
        return [
            'lat'          => self::ACARS_POSITION_DEFAULT,
            'lon'          => self::ACARS_POSITION_DEFAULT,
            'altitude_msl' => self::ACARS_POSITION_DEFAULT,
        ];
    }

    private function flightEventTime($value): ?Carbon
    {
        if (!is_string($value) || strtotime($value) === false) {
            return null;
        }
        try {
            // The same parse as the flightData rows at filing, so a line gets the same time from either path
            $time = Carbon::createFromTimeString($value);
        } catch (\Throwable $e) {
            // An unparseable timestamp is the caller's error, answered with a 422
            return null;
        }
        return $time instanceof Carbon ? $time : null;
    }

    /**
     * The aircraft of the pilot's own SimBrief briefing for the flight, if any
     */
    private function briefedAircraftId(Flight $flight, User $user): ?int
    {
        $aircraftId = $flight->simbrief()->where('user_id', $user->id)->value('aircraft_id');
        return $aircraftId === null ? null : (int) $aircraftId;
    }
    public function charter(Request $request)
    {
        $flight_num = $request->number;
        Log::debug($request->all());
        $aircraft = is_numeric($request->aircraft) ? Aircraft::find((int) $request->aircraft) : null;
        if ($aircraft === null) {
            return $this->refusal('Aircraft '.$request->aircraft.' not found', 'invalid_request', 422);
        }
        foreach (['departure', 'arrival'] as $field) {
            if (!is_string($request->input($field)) || trim($request->input($field)) === '') {
                return $this->refusal($field.' is required', 'invalid_request', 422);
            }
        }
        if (is_numeric($flight_num)) {
            $airline_id = env('SC3_CHARTER_AIRLINE_ID', Airline::first()->id);
        } else {
            $icao_iata = substr($flight_num, 0, 3);

            // Check if the first 3 contains numbers. If so, the shorter IATA code was used. 3 Characters, ICAO
            if (preg_match('~[0-9]+~', $icao_iata)) {
                // IATA Code.
                $query = ['iata' => substr($icao_iata, 0, 2)];

            } else {
                // ICAO Code.
                $query = ['icao' => $icao_iata];
            }
            $airline = Airline::where($query)->first();
            if ($airline == null) {
                $airline = Airline::first();
            }
            $airline_id = $airline->id;
            $flight_num = filter_var($flight_num, FILTER_SANITIZE_NUMBER_INT);
        }

        $attrs = [
            'flight_number'  => $flight_num,
            'route_code'     => env('SC3_CHARTER_ROUTE_CODE', "SC3"),
            'airline_id'     => $airline_id,
            'flight_type'    => FlightType::CHARTER_PAX_ONLY,
            'minutes'        => 0,
            'hours'          => 0,
            'active'         => true,
            'visible'        => false,
            'dpt_airport_id' => $request->departure,
            'arr_airport_id' => $request->arrival,
            'owner_type'     => AppServiceProvider::class,
            'user_id'        => Auth::user()->id
        ];
        // Check if the pirep already exists.
        try {
            $flight = $this->flightService->createFlight($attrs);
        } catch (\Exception $exception) {
            // Randomize the flight code
            $attrs['route_code'] = Str::random(4);
            $flight = $this->flightService->createFlight($attrs);
        }
        // phpVMS adds a missing airport, unless its airport lookup is on and finds nothing
        foreach ([$flight->dpt_airport_id, $flight->arr_airport_id] as $airportId) {
            if (Airport::find($airportId) === null) {
                $flight->delete();
                return $this->refusal('Airport '.$airportId.' not found', 'unknown_airport', 422);
            }
        }

        try {
            $bid = $this->bidService->addBid($flight, $request->user(), $aircraft);
        } catch (AbstractHttpException $e) {
            $flight->delete();
            return $this->exceptionRefusal($e, 409);
        }
        // force assign the aircraft to the bid
        $bid = Bid::find($bid->id);
        $bid->aircraft_id = $aircraft->id;
        $bid->save();
        return response()->json(['bidID' => $bid->id]);
    }
    public function complete(Request $request)
    {
        $pirep = Pirep::where(['id' => $request->input('uuid'), 'user_id' => Auth::id()])->first();
        if ($pirep === null) {
            return $this->refusal('No active flight', 'no_active_flight', 404);
        }
        // A retry after a failure past the save must not log the flight a second time
        if (in_array($pirep->state, [PirepState::PENDING, PirepState::ACCEPTED, PirepState::REJECTED], true)) {
            return response()->json($this->filedAnswer($pirep));
        }
        if (!in_array($pirep->state, [PirepState::IN_PROGRESS, PirepState::PAUSED], true)) {
            return $this->refusal('This flight is not in progress', 'flight_not_in_progress', 409);
        }

        $input = $request->all();
        $input['flightLog'] = $input['flightLog'] ?? null;
        $input['flightData'] = $input['flightData'] ?? null;
        if (gettype($input['flightLog']) === "string") {
            $input['flightLog'] = base64_decode($input['flightLog'], true);
            $input['flightLog'] = explode("\n", $input['flightLog']);
            //logger("Flight Log");
            //logger($input['flightLog']);
        }
        if (gettype($input['flightData']) === "string") {
            $input['flightData'] = base64_decode($input['flightData'], true);
            $input['flightData'] = json_decode($input['flightData'], true);
            //logger("Flight Data");
            //logger($input['flightData']);
        }
        // A retry gets the same answer, so a body that cannot be read is refused rather than failing with a 500
        if (!is_array($input['flightData']) || !array_is_list($input['flightData'])) {
            return $this->refusal('flightData must be a base64 JSON array of events', 'invalid_request', 422);
        }

        //Log::debug("Found Pirep to close out");
        $pirep->status = PirepStatus::ARRIVED;
        $pirep->state = PirepState::PENDING;
        $pirep->source = PirepSource::ACARS;
        $pirep->source_name = $this->sourceName($request);
        $pirep->landing_rate = $input['landingRate'];
        $pirep->fuel_used = $input['fuelUsed'];
        $pirep->flight_time = $input['flightTime'] * 60;
        $pirep->route = $input['route'] ? join(" ", $input['route']) : '';
        $pirep->submitted_at = Carbon::now('UTC');
        $storedLogIds = Acars::where(['pirep_id' => $pirep->id, 'type' => AcarsType::LOG])->pluck('id')->flip();
        $acarsSource = $this->acarsSource($request);
        foreach ($input['flightData'] as $data) {
            // A line already stored by flights/events keeps its row
            $eventId = $this->flightEventId($pirep->id, $data['eventId'] ?? null);
            if ($eventId === null || !$storedLogIds->has($eventId)) {
                $log_item = new Acars();
                $log_item->type = AcarsType::LOG;
                $log_item->log = $data['message'];
                $log_item->created_at = Carbon::createFromTimeString($data['eventTimestamp']);
                $log_item->source = $acarsSource;
                $pirep->acars_logs()->save($log_item);
            }
            // hotfix for block fuel. Replace this code when block fuel is properly implemented in smartCARS
            if (str_contains($data['message'], "Pushing back with")) {
                // example: "Pushing back with 1000 lbs of fuel"
                // extract the number and units
                //logger($data['message']);
                preg_match('/Pushing back with (\d+) (\w+) of fuel/', $data['message'], $matches);
                // check if we have 3 matches
                if (count($matches) !== 3) {
                    continue;
                }
                //logger($matches);
                $fuel_amount = intval($matches[1]);
                $fuel_units = $matches[2];
                // Convert kg to lbs if necessary
                if (strtolower($fuel_units) === 'kgs') {
                    $fuel_amount = $fuel_amount * 2.20462; // 1 kg = 2.20462 lbs
                }
                $pirep->block_fuel = $fuel_amount;
            }
        }
        if (!is_null($input['comments'] ?? null)) {
            foreach ($input['flightLog'] as $comment) {
                if (str_contains($comment, " Comment:")) {
                    $pirep->comments()->create([
                        'user_id' => Auth::user()->id,
                        'comment' => $comment
                    ]);
                }
            }
        }
        // Now the raw data
        PirepLog::create([
            'pirep_id' => $pirep->id,
            'log'      => gzencode(json_encode($input['flightData']))
        ]);
        $distanceFlown = $this->positiveNumber($input['distanceFlown'] ?? null);
        $pirep->distance = $distanceFlown ?? PirepDistanceCalculation::calculatePirepDistance($pirep);
        $this->applyFiledValues($pirep, $input);
        try {
            $this->applyAircraftChange($pirep, $input['aircraft'] ?? null);
        } catch (\Throwable $e) {
            Log::error('smartCARS: could not check the aircraft change of PIREP '.$pirep->id.': '.$e->getMessage());
        }
        $pirep->save();
        // The PIREP is already saved as pending: a failure here must not stop the submit below
        try {
            $this->applyFiledLoads($pirep, $input);
        } catch (\Throwable $e) {
            Log::error('smartCARS: could not save the filed loads of PIREP '.$pirep->id.': '.$e->getMessage());
        }
        try {
            $this->saveFiledFields($pirep, $input);
        } catch (\Throwable $e) {
            Log::error('smartCARS: could not save the fields of PIREP '.$pirep->id.': '.$e->getMessage());
        }
        try {
            if (!Acars::where(['pirep_id' => $pirep->id, 'type' => AcarsType::ROUTE])->exists()) {
                $this->pirepService->saveRoute($pirep);
            }
        } catch (\Throwable $e) {
            Log::error('smartCARS: could not save the route of PIREP '.$pirep->id.': '.$e->getMessage());
        }
        $this->pirepService->submit($pirep);
        // A VA's PirepFiled listener or the rank's auto-approve may have changed the state during submit
        $pirep->refresh();
        return response()->json($this->filedAnswer($pirep));

    }
    private function filedStatus(Pirep $pirep): ?string
    {
        return self::FILED_STATUSES[$pirep->state] ?? null;
    }
    private function filedAnswer(Pirep $pirep): array
    {
        $answer = ['pirepID' => $pirep->id];
        $status = $this->filedStatus($pirep);
        if ($status !== null) {
            $answer['status'] = $status;
        }
        return $answer;
    }
    private function applyFiledValues(Pirep $pirep, array $input): void
    {
        $score = $this->finiteNumber($input['score'] ?? null);
        if ($score !== null && $score >= 0 && $score <= 100) {
            $pirep->score = (int) round($score);
        }
        $cruiseLevel = $this->positiveNumber($input['cruiseLevel'] ?? null);
        if ($cruiseLevel !== null) {
            $pirep->level = (int) round($cruiseLevel);
        }
        $blockFuel = $this->positiveNumber($input['blockFuel'] ?? null);
        if ($blockFuel !== null) {
            $pirep->block_fuel = $blockFuel;
        }
        $zfw = $this->positiveNumber($input['zfw'] ?? null);
        if ($zfw !== null) {
            $pirep->zfw = $zfw;
        }
        $blockOff = $this->utcTime($input['blockOffTime'] ?? null);
        $blockOn = $this->utcTime($input['blockOnTime'] ?? null);
        if ($blockOff === null && $blockOn === null) {
            return;
        }
        $off = $blockOff ?? $this->storedTime($pirep, 'block_off_time');
        $on = $blockOn ?? $this->storedTime($pirep, 'block_on_time');
        if ($off !== null && $on !== null && $off->greaterThan($on)) {
            Log::warning('smartCARS: PIREP '.$pirep->id.' block off time would be after its block on time, the filed block times are ignored');
            return;
        }
        if ($blockOff !== null) {
            $pirep->block_off_time = $blockOff;
        }
        if ($blockOn !== null) {
            $pirep->block_on_time = $blockOn;
        }
    }
    /**
     * Read from the raw attributes: phpVMS's CarbonCast turns a null column into the current time
     */
    private function storedTime(Pirep $pirep, string $key): ?Carbon
    {
        $raw = $pirep->getRawOriginal($key);
        if ($raw === null || $raw === '') {
            return null;
        }
        if ($raw instanceof \DateTimeInterface) {
            return Carbon::instance($raw)->setTimezone('UTC');
        }
        try {
            return Carbon::parse((string) $raw, 'UTC');
        } catch (\Throwable $e) {
            Log::warning('smartCARS: PIREP '.$pirep->id.' has an unreadable '.$key.': '.$e->getMessage());
            return null;
        }
    }
    private function applyAircraftChange(Pirep $pirep, $aircraftId): void
    {
        if (!is_numeric($aircraftId) || (int) $aircraftId === (int) $pirep->aircraft_id) {
            return;
        }
        $aircraftId = (int) $aircraftId;
        $reason = $this->aircraftChangeRefusal($pirep, $aircraftId);
        if ($reason !== null) {
            Log::info('smartCARS: PIREP '.$pirep->id.' keeps aircraft '.$pirep->aircraft_id.' instead of '.$aircraftId.': '.$reason);
            return;
        }
        $pirep->aircraft_id = $aircraftId;
        $pirep->unsetRelation('aircraft');
    }
    private function aircraftChangeRefusal(Pirep $pirep, int $aircraftId): ?string
    {
        $aircraft = Aircraft::find($aircraftId);
        if ($aircraft === null) {
            return 'it does not exist';
        }
        if (!app(UserService::class)->aircraftAllowed($pirep->user, $aircraftId)) {
            return 'the pilot may not fly its subfleet';
        }
        if ($aircraft->state !== AircraftState::PARKED) {
            return 'it is not parked';
        }
        if (setting('pireps.only_aircraft_at_dpt_airport') && $aircraft->airport_id !== $pirep->dpt_airport_id) {
            return 'it is not at the departure airport';
        }
        $isOnAnotherFlight = Pirep::where('aircraft_id', $aircraftId)
            ->where('id', '!=', $pirep->id)
            ->whereIn('state', [PirepState::IN_PROGRESS, PirepState::PAUSED])
            ->exists();
        if ($isOnAnotherFlight) {
            return 'it is on another flight in progress';
        }
        if (setting('simbrief.block_aircraft', false)) {
            $isBriefedForAnotherPilot = SimBrief::where('aircraft_id', $aircraftId)
                ->where('user_id', '!=', $pirep->user_id)
                ->whereNotNull('flight_id')
                ->exists();
            if ($isBriefedForAnotherPilot) {
                return 'another pilot has an active SimBrief briefing for it';
            }
        }
        if (setting('bids.block_aircraft', false)) {
            $isBookedByAnotherPilot = Bid::where('aircraft_id', $aircraftId)
                ->where('user_id', '!=', $pirep->user_id)
                ->exists();
            if ($isBookedByAnotherPilot) {
                return 'another pilot has booked it';
            }
        }
        return null;
    }
    private function applyFiledLoads(Pirep $pirep, array $input): void
    {
        $passengers = $this->finiteNumber($input['passengers'] ?? null);
        $passengers = $passengers !== null && $passengers >= 0 && floor($passengers) === $passengers
            ? (int) $passengers
            : null;
        $cargo = $this->finiteNumber($input['cargo'] ?? null);
        if ($cargo !== null && $cargo >= 0) {
            // phpVMS counts cargo fares in the VA's display weight unit, as its SimBrief form does
            $cargo = (int) round(setting('units.weight') === 'kg' ? $cargo / self::LBS_PER_KG : $cargo);
        } else {
            $cargo = null;
        }
        if ($passengers === null && $cargo === null) {
            return;
        }

        $allFares = $this->freshFares($pirep);
        $totals = [FareType::PASSENGER => $passengers, FareType::CARGO => $cargo];

        $fares = [];
        $keptTypes = [];
        foreach ([FareType::PASSENGER, FareType::CARGO] as $type) {
            $typeFares = $allFares->filter(fn ($fare) => $fare->type === $type && $fare->capacity > 0)->values();
            if ($totals[$type] === null || $typeFares->isEmpty()) {
                if ($totals[$type] !== null) {
                    Log::info('smartCARS: PIREP '.$pirep->id.' has no '.($type === FareType::PASSENGER ? 'passenger' : 'cargo').' fare for the filed load');
                }
                $keptTypes[] = $type;
                continue;
            }
            $counts = $this->splitByCapacity($totals[$type], $typeFares->pluck('capacity')->all());
            foreach ($typeFares as $index => $fare) {
                $fares[] = new PirepFare(['fare_id' => $fare->id, 'count' => $counts[$index]]);
            }
        }
        if (empty($fares)) {
            return;
        }

        // saveToPirep deletes every fare row, so the rows of a kind not filed are put back as they were
        $kept = PirepFare::where('pirep_id', $pirep->id)->whereIn('type', $keptTypes)->get();
        DB::transaction(function () use ($pirep, $fares, $kept) {
            $this->saveFaresOnce($pirep, $fares);
            foreach ($kept as $row) {
                $row->replicate()->save();
            }
        });
    }
    /**
     * getFareWithPivot writes the subfleet and flight overrides onto the Fare models it is given,
     * so the module's fare list comes from fresh models and saveToPirep gets the PIREP without
     * cached relations: a percentage override then applies once
     */
    private function freshFares(Pirep $pirep)
    {
        $flight = $pirep->flight_id !== null ? Flight::find($pirep->flight_id) : null;
        return $this->fareService->getAllFares($flight, Aircraft::find($pirep->aircraft_id)->subfleet);
    }
    private function saveFaresOnce(Pirep $pirep, array $fares): void
    {
        $pirep->unsetRelation('aircraft');
        $pirep->unsetRelation('flight');
        $this->fareService->saveToPirep($pirep, $fares);
    }
    /**
     * Largest remainder split, so the counts are integers that add up to the total
     */
    private function splitByCapacity(int $total, array $capacities): array
    {
        $capacitySum = array_sum($capacities);
        $counts = [];
        $remainders = [];
        foreach ($capacities as $index => $capacity) {
            $share = $total * $capacity / $capacitySum;
            $counts[$index] = (int) floor($share);
            $remainders[$index] = $share - $counts[$index];
        }
        arsort($remainders);
        $left = $total - array_sum($counts);
        foreach (array_keys($remainders) as $index) {
            if ($left <= 0) {
                break;
            }
            $counts[$index]++;
            $left--;
        }
        return $counts;
    }
    private function saveFiledFields(Pirep $pirep, array $input): void
    {
        $values = [
            self::NETWORK_FIELD_SLUG   => [self::NETWORK_FIELD_NAME, $input['network'] ?? null],
            self::SIMULATOR_FIELD_SLUG => [self::SIMULATOR_FIELD_NAME, $input['simVersion'] ?? null],
        ];
        foreach ($values as $slug => [$name, $value]) {
            if (!is_string($value) || trim($value) === '') {
                continue;
            }
            PirepFieldValue::updateOrCreate(
                ['pirep_id' => $pirep->id, 'slug' => $slug],
                ['name' => $name, 'value' => Str::limit(trim($value), self::FIELD_VALUE_MAX_LENGTH, ''), 'source' => PirepFieldSource::ACARS]
            );
        }
    }
    public function search(Request $request)
    {
        $limit = self::SEARCH_LIMIT;

        if ($request->has('limit') && $request->query('limit') !== null) {
            $limit = $request->query('limit');
            $limit = min($limit, self::SEARCH_LIMIT);
        }
        $limit = (int) $limit;
        if ($limit < 1) {
            $limit = self::SEARCH_LIMIT;
        }

        $cursor = $this->decodeCursor($request->query('cursor'));
        if ($cursor === false) {
            return $this->invalidCursorJson();
        }

        return $this->pagedJson($request, $this->searchQuery($request), $limit, $cursor);
    }

    public function destinations(Request $request)
    {
        if (in_array($request->query('routes'), ['1', 'true'], true)) {
            return $this->destinationRoutes($request);
        }

        $departure = strtoupper(trim((string) $request->query('departure', '')));
        if ($departure === '') {
            return $this->refusal('departure is required', 'invalid_request', 400);
        }

        $departureId = Airport::where('icao', $departure)->value('id');
        if ($departureId === null) {
            return response()->json([]);
        }
        if (setting('pilots.only_flights_from_current') && $departureId !== $request->user()->curr_airport_id) {
            return response()->json([]);
        }

        $destinations = Flight::where('flights.dpt_airport_id', $departureId)
            ->whereColumn('flights.dpt_airport_id', '!=', 'flights.arr_airport_id')
            ->where('flights.active', true)
            ->where('flights.visible', true)
            ->join('airports', 'airports.id', '=', 'flights.arr_airport_id')
            ->groupBy('flights.arr_airport_id', 'airports.icao')
            ->selectRaw('airports.icao as icao, COUNT(*) as flight_count')
            ->orderByDesc('flight_count')
            ->orderBy('airports.icao')
            ->get()
            ->map(function ($row) {
                return ['icao' => $row->icao, 'flights' => (int) $row->flight_count];
            })
            ->values();

        return response()->json($destinations);
    }
    public function searchArrivals(Request $request)
    {
        $departures = $request->input('departureAirports');
        if (!is_array($departures) || empty($departures)) {
            return $this->refusal('departureAirports must be a non-empty array', 'invalid_request', 400);
        }

        $perArrivalLimit = (int)$request->input('perArrivalLimit', 0);
        $isPaged = $perArrivalLimit < 1;
        $cursor = null;
        if ($isPaged) {
            $cursor = $this->decodeCursor($request->input('cursor'));
            if ($cursor === false) {
                return $this->invalidCursorJson();
            }
        }

        if (setting('pilots.only_flights_from_current')) {
            $departureIds = [$request->user()->curr_airport_id];
        } else {
            $departureIds = Airport::whereIn('icao', array_values(array_filter($departures, 'is_string')))->pluck('id')->all();
            if (empty($departureIds)) {
                return $this->emptySearchJson($isPaged);
            }
        }

        $query = Flight::whereIn('flights.dpt_airport_id', $departureIds)
            ->whereColumn('flights.dpt_airport_id', '!=', 'flights.arr_airport_id')
            ->where($this->rangeConditions(
                $request->only(['minimumDistance', 'maximumDistance', 'minimumFlightTime', 'maximumFlightTime'])
            ))
            ->where('flights.active', true)
            ->where('flights.visible', true);

        $arrivals = $request->input('arrivalAirports');
        if (is_array($arrivals) && !empty($arrivals)) {
            $arrivalIds = Airport::whereIn('icao', array_values(array_filter($arrivals, 'is_string')))->pluck('id');
            if ($arrivalIds->isEmpty()) {
                return $this->emptySearchJson($isPaged);
            }
            $query->whereIn('flights.arr_airport_id', $arrivalIds);
        }

        $aircraft = $request->input('aircraft');
        if (is_array($aircraft) && !empty($aircraft)) {
            $aircraftIds = array_map('intval', array_filter($aircraft, 'is_numeric'));
            if (empty($aircraftIds)) {
                return $this->emptySearchJson($isPaged);
            }
            $query->whereHas('subfleets.aircraft', function ($q) use ($aircraftIds) {
                $q->whereIn('aircraft.id', $aircraftIds);
            });
        }

        $limit = (int)$request->input('limit', self::ARRIVAL_SEARCH_LIMIT);
        if ($limit < 1 || $limit > self::ARRIVAL_SEARCH_LIMIT) {
            $limit = self::ARRIVAL_SEARCH_LIMIT;
        }

        if ($isPaged) {
            return $this->pagedJson($request, $query, $limit, $cursor);
        }

        $this->withRouteOrder($query);

        // cursor() skips eager loading, so the kept flights are reloaded with their relations below
        $countsByArrival = [];
        $ids = [];
        foreach ($query->cursor() as $candidate) {
            $arrivalId = $candidate->arr_airport_id;
            $countsByArrival[$arrivalId] = ($countsByArrival[$arrivalId] ?? 0) + 1;
            if ($countsByArrival[$arrivalId] > $perArrivalLimit) {
                continue;
            }
            $ids[] = $candidate->id;
            if (count($ids) >= $limit) {
                break;
            }
        }
        $position = array_flip($ids);
        $flights = Flight::whereIn('id', $ids)
            ->with('subfleets', 'subfleets.aircraft', 'airline', 'field_values')
            ->get()
            ->sortBy(fn ($flight) => $position[$flight->id])
            ->values();

        $output = [];
        foreach ($flights as $flight) {
            $output[] = $this->flightOutput($request, $flight);
        }

        return response()->json($output);
    }
    private function searchQuery(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        $query = [];

        if ($request->has('departureAirport') && $request->query('departureAirport') !== null) {
            $apt = Airport::where('icao', $request->query('departureAirport'))->first();
            if (!is_null($apt)) {
                $query['flights.dpt_airport_id'] = $apt->id;
            }
        }
        // If Current Airport Setting is enabled, force current airport as the search
        if (setting('pilots.only_flights_from_current')) {
            $query['flights.dpt_airport_id'] = $request->user()->curr_airport_id;
        }
        if ($request->has('arrivalAirport') && $request->query('arrivalAirport') !== null) {
            $apt = Airport::where('icao', $request->query('arrivalAirport'))->first();
            if (!is_null($apt)) {
                $query['flights.arr_airport_id'] = $apt->id;
            }
        }

        $query = array_merge($query, $this->rangeConditions(
            $request->only(['minimumDistance', 'maximumDistance', 'minimumFlightTime', 'maximumFlightTime'])
        ));

        $flights = Flight::where($query)
            ->where('flights.active', true)
            ->where('flights.visible', true)
            ->whereColumn('flights.dpt_airport_id', '!=', 'flights.arr_airport_id');

        $callsign = strtoupper(trim((string) $request->query('callsign', '')));
        if ($callsign !== '') {
            $this->whereCallsignContains($flights, $callsign);
        }

        $aircraftId = $request->query('aircraft');
        if (is_numeric($aircraftId)) {
            $flights->whereHas('subfleets.aircraft', function ($q) use ($aircraftId) {
                $q->where('aircraft.id', (int) $aircraftId);
            });
        }

        return $flights;
    }
    private function destinationRoutes(Request $request)
    {
        $base = $this->searchQuery($request);

        $total = (clone $base)
            ->toBase()
            ->selectRaw('COUNT(DISTINCT flights.dpt_airport_id, flights.arr_airport_id) as n')
            ->value('n');

        $rows = (clone $base)
            ->toBase()
            ->leftJoin('airports as sc_dpt', 'sc_dpt.id', '=', 'flights.dpt_airport_id')
            ->leftJoin('airports as sc_arr', 'sc_arr.id', '=', 'flights.arr_airport_id')
            ->groupBy('flights.dpt_airport_id', 'flights.arr_airport_id')
            ->selectRaw('flights.dpt_airport_id as departure, flights.arr_airport_id as arrival, COUNT(*) as flight_count, MIN(flights.distance) as distance, MIN(flights.flight_time) as min_time, MAX(flights.flight_time) as max_time, MIN('.$this->routeKeySql().') as sc_route_key')
            ->orderByRaw('sc_route_key, flights.dpt_airport_id, flights.arr_airport_id')
            ->limit(self::DESTINATION_ROUTES_LIMIT)
            ->get();

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'departure'         => (string) $row->departure,
                'arrival'           => (string) $row->arrival,
                'flights'           => (int) $row->flight_count,
                'distance'          => (float) $row->distance,
                'minimumFlightTime' => floatval(number_format($row->min_time / 60, 2)),
                'maximumFlightTime' => floatval(number_format($row->max_time / 60, 2)),
            ];
        }

        return response()->json($items)->withHeaders([self::TOTAL_ROUTES_HEADER => (string) (int) $total]);
    }
    private function routeKeySql(): string
    {
        return 'COALESCE(CASE WHEN (sc_dpt.lat = 0 AND sc_dpt.lon = 0) OR (sc_arr.lat = 0 AND sc_arr.lon = 0) THEN NULL ELSE ROUND(3440.065 * 2 * ASIN(LEAST(1, SQRT(POWER(SIN(RADIANS(sc_arr.lat - sc_dpt.lat) / 2), 2) + COS(RADIANS(sc_dpt.lat)) * COS(RADIANS(sc_arr.lat)) * POWER(SIN(RADIANS(sc_arr.lon - sc_dpt.lon) / 2), 2)))) * 1000) END, 999999999)';
    }
    private function withRouteOrder($query)
    {
        return $query
            ->leftJoin('airports as sc_dpt', 'sc_dpt.id', '=', 'flights.dpt_airport_id')
            ->leftJoin('airports as sc_arr', 'sc_arr.id', '=', 'flights.arr_airport_id')
            ->select('flights.*')
            ->selectRaw($this->routeKeySql().' as sc_route_key')
            ->orderByRaw('sc_route_key, '.self::ROUTE_TIE_BREAK_SQL);
    }
    private function decodeCursor($raw): array|false|null
    {
        if ($raw === null) {
            return null;
        }
        if (!is_string($raw) || preg_match('/^[A-Za-z0-9_-]+$/', $raw) !== 1) {
            return false;
        }
        $base64 = strtr($raw, '-_', '+/');
        $base64 .= str_repeat('=', (4 - strlen($base64) % 4) % 4);
        $json = base64_decode($base64, true);
        if ($json === false) {
            return false;
        }
        $cursor = json_decode($json, true);
        if (
            !is_array($cursor)
            || !is_int($cursor['k'] ?? null)
            || !is_string($cursor['d'] ?? null)
            || !is_string($cursor['a'] ?? null)
            || !is_string($cursor['i'] ?? null)
        ) {
            return false;
        }
        return ['k' => $cursor['k'], 'd' => $cursor['d'], 'a' => $cursor['a'], 'i' => $cursor['i']];
    }
    private function encodeCursor(Flight $flight): string
    {
        // The key is a DOUBLE in SQL and may come back as a float or a numeric string
        $json = json_encode([
            'k' => (int) round((float) $flight->sc_route_key),
            'd' => (string) $flight->dpt_airport_id,
            'a' => (string) $flight->arr_airport_id,
            'i' => (string) $flight->id,
        ]);
        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }
    private function invalidCursorJson()
    {
        return $this->refusal(self::INVALID_CURSOR_MESSAGE, 'invalid_request', 400);
    }
    private function emptySearchJson(bool $isPaged)
    {
        $response = response()->json([]);
        if ($isPaged) {
            $response->withHeaders([
                self::TOTAL_FLIGHTS_HEADER => '0',
                self::TOTAL_ROUTES_HEADER  => '0',
            ]);
        }
        return $response;
    }
    private function pagedJson(Request $request, $baseQuery, int $limit, ?array $cursor)
    {
        $totalFlights = (clone $baseQuery)->count('flights.id');
        $totalRoutes = (clone $baseQuery)
            ->toBase()
            ->selectRaw('COUNT(DISTINCT flights.dpt_airport_id, flights.arr_airport_id) as n')
            ->value('n');

        $page = $this->withRouteOrder(clone $baseQuery);
        if ($cursor !== null) {
            $page->whereRaw(
                '('.$this->routeKeySql().', '.self::ROUTE_TIE_BREAK_SQL.') > (?, ?, ?, ?)',
                [$cursor['k'], $cursor['d'], $cursor['a'], $cursor['i']]
            );
        }
        $flights = $page->with('subfleets', 'subfleets.aircraft', 'airline', 'field_values')->take($limit + 1)->get();

        $headers = [
            self::TOTAL_FLIGHTS_HEADER => (string) (int) $totalFlights,
            self::TOTAL_ROUTES_HEADER  => (string) (int) $totalRoutes,
        ];
        if ($flights->count() > $limit) {
            $flights = $flights->take($limit);
            $headers[self::NEXT_CURSOR_HEADER] = $this->encodeCursor($flights->last());
        }

        $output = [];
        foreach ($flights as $flight) {
            $output[] = $this->flightOutput($request, $flight);
        }

        return response()->json($output)->withHeaders($headers);
    }
    /**
     * The callsign is the airline code followed by the flight number. Both codes match: the pilot sees one of
     * them, and smartCARS 3's suggested flights send the ICAO code of the pilot's latest PIREP.
     */
    private function whereCallsignContains($query, string $callsign): void
    {
        $pattern = '%'.addcslashes($callsign, '\\%_').'%';
        $query->whereExists(function ($q) use ($pattern) {
            $q->selectRaw('1')
                ->from('airlines as sc_al')
                ->whereColumn('sc_al.id', 'flights.airline_id')
                ->where(function ($w) use ($pattern) {
                    $w->whereRaw("UPPER(CONCAT(COALESCE(sc_al.iata, ''), flights.flight_number)) LIKE ?", [$pattern])
                        ->orWhereRaw('UPPER(CONCAT(sc_al.icao, flights.flight_number)) LIKE ?', [$pattern]);
                });
        });
    }
    private function daysOfWeek(?int $days): array
    {
        $names = [];
        foreach (self::DAY_NAMES as $bit => $name) {
            if ($days !== null && ($days & $bit) !== 0) {
                $names[] = $name;
            }
        }
        return $names;
    }
    private function rangeConditions(array $values): array
    {
        $conditions = [];
        if (array_key_exists('minimumDistance', $values)) {
            $conditions[] = ['flights.distance', '>=', (float) $values['minimumDistance']];
        }
        if (array_key_exists('maximumDistance', $values)) {
            $conditions[] = ['flights.distance', '<=', (float) $values['maximumDistance']];
        }
        if (array_key_exists('minimumFlightTime', $values)) {
            $conditions[] = ['flights.flight_time', '>=', (int) round((float) $values['minimumFlightTime'] * 60)];
        }
        if (array_key_exists('maximumFlightTime', $values)) {
            $conditions[] = ['flights.flight_time', '<=', (int) round((float) $values['maximumFlightTime'] * 60)];
        }
        return $conditions;
    }
    private function flightOutput(Request $request, Flight $flight): array
    {
        $aircraft = [];
        $flight = $this->flightService->filterSubfleets($request->user(), $flight);
        foreach ($flight->subfleets as $subfleet) {
            foreach ($subfleet->aircraft as $acf) {
                $aircraft[] = $acf['id'];
            }
        }
        $ft_converted = floatval(number_format($flight->flight_time / 60, 2));
        $output = [
            "id"               => $flight->id,
            "number"           => $flight->flight_number,
            "code"             => ScSettings::airlineCode($flight->airline),
            "departureAirport" => $flight->dpt_airport_id,
            "arrivalAirport"   => $flight->arr_airport_id,
            "alternateAirport" => $flight->alt_airport_id ?: null,
            "flightLevel"      => $flight->level,
            "route"            => $flight->route ? explode(" ", $flight->route) : [],
            "distance"         => $flight->distance->getResponseUnits()['nmi'],
            "departureTime"    => $flight->dpt_time,
            "arrivalTime"      => $flight->arr_time,
            "flightTime"       => $ft_converted,
            "daysOfWeek"       => $this->daysOfWeek($flight->days),
            "type"             => $this->outputFlightType($request, $flight->flight_type),
            "aircraft"         => sizeof($aircraft) === 1 ? $aircraft[0] : $aircraft,
            "notes"            => $flight->notes
        ];
        $extensions = $this->flightExtensions($flight);
        if ($extensions !== null) {
            $output['extensions'] = $extensions;
        }
        return $output;
    }
    private function flightExtensions(Flight $flight): ?array
    {
        return FieldExtensions::fromNamedValues(
            $flight->field_values->map(fn ($fieldValue) => [$fieldValue->name, $fieldValue->value])
        );
    }
    public function start(Request $request)
    {
        $user = Auth::user();
        $bid = Bid::where(['id' => $request->input('bidID'), 'user_id' => Auth::id()])->first();
        logger($request->all());
        if ($bid === null) {
            return $this->refusal('Booking not found', 'booking_not_found', 404);
        }
        $flight = Flight::find($bid->flight_id);
        $aircraft = $bid->aircraft_id ?? $this->briefedAircraftId($flight, $user);
        if ($aircraft === null) {
            // the pilot can fix this by choosing an aircraft, so it is a refusal and not a server error
            return $this->refusal('No aircraft attached to bid', 'no_aircraft', 409);
        }

        $attrs = [
            'flight_number'    => $flight->flight_number,
            'airline_id'       => $flight->airline_id,
            'route_code'       => $flight->route_code,
            'route_leg'        => $flight->route_leg,
            'flight_type'      => $flight->flight_type,
            'dpt_airport_id'   => $flight->dpt_airport_id,
            'arr_airport_id'   => $flight->arr_airport_id,
            'alt_airport_id'   => $flight->alt_airport_id ?: null,
            'planned_distance' => $flight->distance,
            'aircraft_id'      => $aircraft,
            'flight_id'        => $flight->id,
            'source'           => PirepSource::ACARS,
            'source_name'      => $this->sourceName($request)
        ];
        // find if there's a SimBrief OFP for this flight and user. If so, add it to the PIREP
        $simbrief = $flight->simbrief()->where('user_id', $user->id)->first();

        if ($simbrief !== null) {
            $attrs['simbrief_id'] = $simbrief->id;
        }

        try {
            try {
                $pirep = $this->pirepService->prefile(Auth::user(), $attrs);
            } catch (AbstractHttpException $e) {
                // prefile's refusals (aircraft unavailable, pilot elsewhere, ...) are the pilot's to act on
                Log::warning($e->getMessage());
                return $this->exceptionRefusal($e, 409);
            }
            if (!$this->saveBriefedFares($simbrief, $aircraft, $pirep)) {
                $this->generateFares(Aircraft::find($aircraft), $flight, $pirep);
            }
        } catch (\Throwable $e) {
            // parse the exception to present it cleanly to the user the reason for the error
            Log::error($e);
            return response()->json(['message' => $e->getMessage()], 500);
        }
        Log::debug("Sending TrackingID: ".$pirep->id);
        return response()->json(['trackingID' => $pirep->id]);
        //}
        //return response()->json($existing);

    }
    public function unbook(Request $request)
    {

        $bid = Bid::where(['user_id' => $request->get('pilotID'), 'id' => $request->post('bidID')])->first();
        if ($bid === null) {
            return $this->refusal('Booking not found', 'booking_not_found', 404);
        }
        $flight = Flight::find($bid->flight_id);
        $this->bidService->removeBid($flight, Auth::user());

        // If charter flight that we own, delete the flight.
        if ($flight->owner_type == AppServiceProvider::class) {
            $flight->delete();
        }
        return response()->json(['status' => 200]);
    }
    public function update(Request $request)
    {
        $input = $request->all();
        // Check if there's an active flight under that bid.
        $pirep = Pirep::where(['id' => $input['uuid'], 'user_id' => Auth::id()])->first();
        // if no pirep, return a 404
        if ($pirep === null) {
            return $this->refusal('No active flight', 'no_active_flight', 404);
        }
        // A late or repeated update for a filed or cancelled flight changes nothing
        if (!in_array($pirep->state, [PirepState::IN_PROGRESS, PirepState::PAUSED], true)) {
            return response()->json(['status' => 200]);
        }

        // Check if a phase has changed. An empty, missing or unknown phase keeps the current status
        // (smartCARS sends an empty phase before the first one is known).
        $phase = $input['phase'] ?? null;
        $phaseStatus = is_string($phase) ? $this->phaseToStatus($phase) : null;
        if (($input['paused'] ?? false) === true) {
            $pirep->status = PirepStatus::PAUSED;
        } elseif ($phaseStatus !== null) {
            $pirep->status = $phaseStatus;
        }

        // This section of code is more or less a patch until a better solution is implemented.
        if (
            (
                $pirep->status == PirepStatus::TAKEOFF ||
                $pirep->status == PirepStatus::INIT_CLIM ||
                $pirep->status == PirepStatus::ENROUTE
            ) &&
            $pirep->block_off_time == null) {
            $pirep->block_off_time = Carbon::now();
        }
        if (
            (
                $pirep->status == PirepStatus::LANDED ||
                $pirep->status == PirepStatus::ARRIVED
            ) &&
            $pirep->block_on_time == null) {
            $pirep->block_on_time = Carbon::now();
        }

        if (
            in_array($pirep->status, self::AIRBORNE_STATUSES, true) &&
            PirepFieldValue::where(['pirep_id' => $pirep->id, 'slug' => self::DIVERSION_FIELD_SLUG])->exists()
        ) {
            $pirep->status = PirepStatus::DIVERTED;
        }
        $pirep->updated_at = Carbon::now();

        $flightTimeHours = $this->positiveNumber($input['flightTime'] ?? null);
        if ($flightTimeHours !== null) {
            $pirep->flight_time = (int) round($flightTimeHours * 60);
        } else {
            // Get current flight time by checking time since first ACARS telemetry report.
            $first_acars = $pirep->acars()->first();
            if ($first_acars !== null) {
                $minutes = Carbon::now()->diffInMinutes($first_acars->created_at);
                $pirep->flight_time = $minutes;
            }
        }

        $distanceFlown = $this->positiveNumber($input['distanceFlown'] ?? null);
        if ($distanceFlown !== null) {
            $pirep->distance = $distanceFlown;
        }
        $pirep->save();

        $position = [
            'id'       => Str::orderedUuid(),
            'status'   => $pirep->status,
            'type'     => AcarsType::FLIGHT_PATH,
            'lat'      => $input['latitude'],
            'lon'      => $input['longitude'],
            'distance' => $distanceFlown ?? max(0, $pirep->planned_distance->getResponseUnits()['nmi'] - $input['distanceRemaining']),
            'heading'  => $input['heading'],
            'gs'       => $input['groundSpeed'],
            'source'   => $this->acarsSource($request),
        ];
        // Acars's altitude mutator copies the value into altitude_agl and altitude_msl
        $altitudeAgl = $this->finiteNumber($input['altitudeAgl'] ?? null);
        if ($altitudeAgl !== null && $altitudeAgl >= 0) {
            $position['altitude_agl'] = $altitudeAgl;
            $position['altitude_msl'] = $input['altitude'];
        } else {
            $position['altitude'] = $input['altitude'];
        }
        $ias = $this->positiveNumber($input['ias'] ?? null);
        if ($ias !== null) {
            $position['ias'] = (int) round($ias);
        }
        $verticalSpeed = $this->finiteNumber($input['verticalSpeed'] ?? null);
        if ($verticalSpeed !== null) {
            $position['vs'] = $verticalSpeed;
        }
        $transponder = $input['transponder'] ?? null;
        if (is_string($transponder) && preg_match(self::TRANSPONDER_PATTERN, $transponder) === 1) {
            $position['transponder'] = (int) $transponder;
        }
        $autopilot = $input['autopilot'] ?? null;
        if (is_bool($autopilot)) {
            $position['autopilot'] = $autopilot ? self::AUTOPILOT_ENGAGED : self::AUTOPILOT_DISENGAGED;
        }
        $fuelFlow = $this->finiteNumber($input['fuelFlow'] ?? null);
        $fuelFlow = $fuelFlow !== null && $fuelFlow >= 0 ? round($fuelFlow, 2) : null;
        // lbs per hour, phpVMS's internal fuel unit; the Acars model does not convert fuel_flow
        if ($fuelFlow !== null && $fuelFlow <= self::ACARS_DECIMAL_MAX) {
            $position['fuel_flow'] = $fuelFlow;
        }
        $simTime = $this->simTimeColumn($input['simTime'] ?? null);
        if ($simTime !== null) {
            $position['sim_time'] = $simTime;
        }
        $row = $pirep->acars()->make($position);
        $fuel = $this->finiteNumber($input['fuel'] ?? null);
        // fuel is cast but not fillable on the Acars model, so make() would drop it; lbs, phpVMS's internal fuel unit
        if ($fuel !== null && $fuel >= 0 && $fuel <= self::ACARS_DECIMAL_MAX) {
            $row->fuel = round($fuel, 2);
        }
        $row->save();
    }

    private function finiteNumber($value): ?float
    {
        if (!is_int($value) && !is_float($value) && !(is_string($value) && is_numeric($value))) {
            return null;
        }
        $number = (float) $value;
        return is_finite($number) ? $number : null;
    }

    private function positiveNumber($value): ?float
    {
        $number = $this->finiteNumber($value);
        return $number !== null && $number > 0 ? $number : null;
    }

    private function utcTime($value): ?Carbon
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}/', $value) !== 1) {
            return null;
        }
        try {
            return Carbon::parse($value)->setTimezone('UTC');
        } catch (\Throwable $e) {
            Log::warning('smartCARS: ignoring unparseable time '.$value.': '.$e->getMessage());
            return null;
        }
    }

    public function phaseToStatus(string $phase)
    {
        // smartCARS 3's names, and the generic names smartCARS 4 sends with the flight-phases capability
        // (pre_flight, taxi_out, taxi_in and complete in place of boarding, taxi, taxi_to_gate and deboarding)
        switch(strtolower($phase)) {
            case 'boarding':
            case 'pre_flight':
                return PirepStatus::BOARDING;
            case 'push_back':
                return PirepStatus::PUSHBACK_TOW;
            case 'taxi':
            case 'taxi_out':
                return PirepStatus::TAXI;
            case 'take_off':
                return PirepStatus::TAKEOFF;
            case 'rejected_take_off':
                return PirepStatus::TAXI;
            case 'climb_out':
                return PirepStatus::INIT_CLIM;
            case 'climb':
                return PirepStatus::ENROUTE;
            case 'cruise':
                return PirepStatus::ENROUTE;
            case 'descent':
                return PirepStatus::APPROACH;
            case 'approach':
                return PirepStatus::APPROACH_ICAO;
            case 'final':
                return PirepStatus::LANDING;
            case 'landed':
                return PirepStatus::LANDED;
            case 'go_around':
                return PirepStatus::APPROACH;
            case 'taxi_to_gate':
            case 'taxi_in':
                return PirepStatus::LANDED;
            case 'deboarding':
            case 'complete':
                return PirepStatus::ARRIVED;
            case 'diverted':
                return PirepStatus::DIVERTED;
            default:
                return null;
        }
    }
    /**
     * phpVMS rolls the loads when the pilot generates a SimBrief briefing but never copies them to the PIREP
     */
    private function saveBriefedFares(?SimBrief $simbrief, $aircraftId, Pirep $pirep): bool
    {
        if ($simbrief === null || (int) $simbrief->aircraft_id !== (int) $aircraftId) {
            return false;
        }
        try {
            $briefed = json_decode((string) $simbrief->fare_data, true);
            if (!is_array($briefed)) {
                return false;
            }
            $allFares = $this->freshFares($pirep);
            $fares = [];
            foreach ($briefed as $entry) {
                if (!is_array($entry) || !is_numeric($entry['id'] ?? null) || !is_numeric($entry['count'] ?? null)) {
                    continue;
                }
                $fareId = (int) $entry['id'];
                $count = (int) $entry['count'];
                if ($count < 0 || isset($fares[$fareId]) || $allFares->firstWhere('id', $fareId) === null) {
                    continue;
                }
                $fares[$fareId] = new PirepFare(['fare_id' => $fareId, 'count' => $count]);
            }
            if (empty($fares)) {
                return false;
            }
            $this->saveFaresOnce($pirep, array_values($fares));
            return true;
        } catch (\Throwable $e) {
            Log::error('smartCARS: could not use the SimBrief loads for PIREP '.$pirep->id.': '.$e->getMessage());
            return false;
        }
    }
    private function generateFares($aircraft, $flight, $pirep)
    {
        // Figure out the proper fares to use for this flight/aircraft
        $all_fares = $this->fareService->getFareWithOverrides($aircraft->subfleet->fares, $flight->fares);

        // TODO: Reconcile the fares for this aircraft w/ proper for the flight/subfleet

        // Get passenger and baggage weights with failsafe defaults
        if ($flight->flight_type === FlightType::CHARTER_PAX_ONLY) {
            $bag_weight = setting('simbrief.charter_baggage_weight', 28);
        } else {
            $bag_weight = setting('simbrief.noncharter_baggage_weight', 35);
        }

        // Get the load factors with failsafe for loadmax if nothing is defined
        $lfactor = $flight->load_factor ?? setting('flights.default_load_factor');
        $lfactorv = $flight->load_factor_variance ?? setting('flights.load_factor_variance');

        $loadmin = $lfactor - $lfactorv;
        $loadmin = max($loadmin, 0);

        $loadmax = $lfactor + $lfactorv;
        $loadmax = min($loadmax, 100);

        if ($loadmax === 0) {
            $loadmax = 100;
        }

        if (setting('flights.use_cargo_load_factor', false)) {
            $cgolfactor = $flight->load_factor ?? setting('flights.default_cargo_load_factor');
            $cgolfactorv = $flight->load_factor_variance ?? setting('flights.cargo_load_factor_variance');

            $cgoloadmin = $cgolfactor - $cgolfactorv;
            $cgoloadmin = max($cgoloadmin, 0);

            $cgoloadmax = $cgolfactor + $cgolfactorv;
            $cgoloadmax = min($cgoloadmax, 100);

            if ($cgoloadmax === 0) {
                $cgoloadmax = 100;
            }
        } else {
            $cgoloadmin = $loadmin;
            $cgoloadmax = $loadmax;
        }

        // Load fares for passengers

        $pax_load_sheet = [];
        $tpaxfig = 0;

        /** @var Fare $fare */
        $fares = [];
        foreach ($all_fares as $fare) {
            if ($fare->type !== FareType::PASSENGER || empty($fare->capacity)) {
                continue;
            }
            $fares[] = new PirepFare([
                'fare_id' => $fare->id,
                'count'   => floor(($fare->capacity * rand($loadmin, $loadmax)) / 100)
            ]);

        }

        // Calculate total weights
        if (setting('units.weight') === 'kg') {
            $tbagload = round(($bag_weight * $tpaxfig) / 2.205);
        } else {
            $tbagload = round($bag_weight * $tpaxfig);
        }
        foreach ($all_fares as $fare) {
            if ($fare->type !== FareType::CARGO || empty($fare->capacity)) {
                continue;
            }
            $fares[] = new PirepFare([
                'fare_id' => $fare->id,
                'count'   => ceil((($fare->capacity - $tbagload) * rand($cgoloadmin, $cgoloadmax)) / 100)
            ]);
        }
        // Generate PIREP fares
        $this->fareService->saveToPirep($pirep, $fares);
    }
    public function flightType($type)
    {
        switch($type) {
            case 'J':
            case 'E':
            case 'C':
            case 'G':
            case 'O':
                return 'P';
                break;
            case 'A':
            case 'F':
            case 'H':
            case 'I':
            case 'K':
            case 'M':
            case 'P':
            case 'T':
            case 'W':
            case 'X':
                return 'C';
                break;
        }
    }
}