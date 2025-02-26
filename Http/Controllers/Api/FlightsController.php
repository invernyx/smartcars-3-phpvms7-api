<?php

namespace Modules\SmartCARS3phpVMS7Api\Http\Controllers\Api;

use App\Contracts\Controller;
use App\Events\PirepPrefiled;
use App\Exceptions\AirportNotFound;
use App\Exceptions\UserNotAtAirport;
use App\Models\Acars;
use App\Models\Aircraft;
use App\Models\Airline;
use App\Models\Airport;
use App\Models\Bid;
use App\Models\Enums\AcarsType;
use App\Models\Enums\AircraftStatus;
use App\Models\Enums\FareType;
use App\Models\Enums\FlightType;
use App\Models\Enums\PirepSource;
use App\Models\Enums\PirepState;
use App\Models\Enums\PirepStatus;
use App\Models\Fare;
use App\Models\Flight;
use App\Models\Pirep;
use App\Models\PirepFare;
use App\Models\Subfleet;
use App\Models\User;
use App\Services\BidService;
use App\Services\FareService;
use App\Services\FlightService;
use App\Services\PirepService;
use Carbon\Carbon;
use GuzzleHttp\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Modules\SmartCARS3phpVMS7Api\Actions\PirepDistanceCalculation;
use Modules\SmartCARS3phpVMS7Api\Jobs\CalculatePirepDistance;
use Modules\SmartCARS3phpVMS7Api\Models\ActiveFlight;
use Modules\SmartCARS3phpVMS7Api\Models\PirepLog;
use Modules\SmartCARS3phpVMS7Api\Providers\AppServiceProvider;

/**
 * class ApiController
 * @package Modules\SmartCARS3phpVMS7Api\Http\Controllers\Api
 */
class FlightsController extends Controller
{
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
        $user = User::find($request->get('pilotID'));
        $bid = $this->bidService->addBid($flight, $user);
        // force setting the aircraft selected for smartCARS bidding
        if ($request->input('aircraftID') !== null) {
            $bid = Bid::find($bid->id);
            $bid->aircraft_id = $request->input('aircraftID');
            $bid->save();
        }
        return response()->json(["bidID" => $bid->id]);
    }
    public function rebook(Request $request)
    {
        $bid = Bid::find($request->input('bidID'));
        // force setting the aircraft selected for smartCARS bidding
        $bid->aircraft_id = $request->input('aircraftID');
        $bid->save();
        return response()->json(["bidID" => $bid->id]);
    }
    public function bookings(Request $request)
    {
        $user = User::find($request->get('pilotID'));
        $bids = $this->bidService->findBidsForUser($user);
        $bids->load('flight', 'flight.subfleets', 'flight.subfleets.aircraft');
        $output = [];

        foreach ($bids as $bid) {
            // Aircraft Array
            $aircraft = [];
            if ($bid->flight->simbrief) {
                $aircraft = $bid->flight->simbrief->aircraft->id;
            } elseif ($bid->aircraft_id !== null) {
                $aircraft = $bid->aircraft_id;
            } else {
                foreach ($bid->flight->subfleets->sortBy('name') as $subfleet) {
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

            $output[] = [
                "bidID"            => $bid->id,
                "number"           => $bid->flight->flight_number,
                "code"             => $bid->flight->airline->code,
                "departureAirport" => $bid->flight->dpt_airport_id,
                "arrivalAirport"   => $bid->flight->arr_airport_id,
                "route"            => null,
                "flightLevel"      => $bid->flight->level,
                "distance"         => $bid->flight->distance->local(),
                "departureTime"    => $bid->flight->dpt_time,
                "arrivalTime"      => $bid->flight->arr_time,
                "flightTime"       => $ft_converted,
                "daysOfWeek"       => $bid->flight->days,
                "flightID"         => $bid->flight->id,
                "type"             => $this->flightType($bid->flight->flight_type),
                "aircraft"         => $aircraft,
                "notes"            => $bid->flight->notes
            ];
        }

        return response()->json($output);
    }
    public function cancel(Request $request)
    {
        $input = $request->all();
        if (isset($input['uuid'])) {
            $pirep = Pirep::find($input['uuid']);
        } else {
            $af = ActiveFlight::where('bid_id', $input['bidID'])->first();
            $pirep = Pirep::find($af->pirep_id);
        }
        $this->pirepService->cancel($pirep);
        return response()->json(['status' => 200]);
    }
    public function charter(Request $request)
    {
        $flight_num = $request->number;
        Log::debug($request->all());
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
            $attrs['route_code'] = str_random(4);
            $flight = $this->flightService->createFlight($attrs);
        }
        // Grab the Aircraft
        $aircraft = Aircraft::find($request->aircraft);

        $bid = $this->bidService->addBid($flight, $request->user(), $aircraft);
        // force assign the aircraft to the bid
        $bid = Bid::find($bid->id);
        $bid->aircraft_id = $aircraft->id;
        $bid->save();
        return response()->json(['bidID' => $bid->id]);
    }
    public function complete(Request $request)
    {
        $input = $request->all();
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
        if (isset($input['uuid'])) {
            $pirep = Pirep::find($input['uuid']);
        } else {
            $af = ActiveFlight::where('bid_id', $input['bidID'])->first();
            $pirep = Pirep::find($af->pirep_id);
        }

        //Log::debug("Found Pirep to close out");
        $pirep->status = PirepStatus::ARRIVED;
        $pirep->state = PirepState::PENDING;
        $pirep->source = PirepSource::ACARS;
        $pirep->source_name = "smartCARS 3";
        $pirep->landing_rate = $input['landingRate'];
        $pirep->fuel_used = $input['fuelUsed'];
        $pirep->flight_time = $input['flightTime'] * 60;
        $pirep->route = $input['route'] ? join(" ", $input['route']) : '';
        $pirep->submitted_at = Carbon::now('UTC');
        foreach ($input['flightData'] as $data) {
            $log_item = new Acars();
            $log_item->type = AcarsType::LOG;
            $log_item->log = $data['message'];
            $log_item->created_at = Carbon::createFromTimeString($data['eventTimestamp']);
            $pirep->acars_logs()->save($log_item);
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
                if (strtolower($fuel_units) === 'kg') {
                    $fuel_amount = $fuel_amount * 2.20462; // 1 kg = 2.20462 lbs
                }
                $pirep->block_fuel = $fuel_amount;
            }
        }
        if (!is_null($input['comments'])) {
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
        $pirep->distance = PirepDistanceCalculation::calculatePirepDistance($pirep);
        $pirep->save();
        ActiveFlight::where('pirep_id', $pirep->id)->delete();
        $this->pirepService->submit($pirep);
        return response()->json(['pirepID' => $pirep->id]);

    }
    public function search(Request $request)
    {
        $output = [];

        $query = [];
        $subfleet = null;
        $limit = 100;

        if ($request->has('limit') && $request->query('limit') !== null) {
            $limit = $request->query('limit');
            $limit = min($limit, 100);
        }

        if ($request->has('departureAirport') && $request->query('departureAirport') !== null) {
            $apt = Airport::where('icao', $request->query('departureAirport'))->first();
            if (!is_null($apt)) {
                $query['dpt_airport_id'] = $apt->id;
            }
        }
        // If Current Airport Setting is enabled, force current airport as the search
        if (setting('pilots.only_flights_from_current')) {
            $query['dpt_airport_id'] = $request->user()->curr_airport_id;
        }
        if ($request->has('arrivalAirport') && $request->query('arrivalAirport') !== null) {
            $apt = Airport::where('icao', $request->query('arrivalAirport'))->first();
            if (!is_null($apt)) {
                $query['arr_airport_id'] = $apt->id;
            }
        }
        if ($request->has('aircraft') && $request->query('aircraft') !== null) {
            // Yank the subfleet by ID
            $apt = Subfleet::find($request->query('aircraft'));
            if (!is_null($apt)) {
                $subfleet = $apt->id;
            }
        }
        if (!empty($subfleet)) {
            if (empty($query)) {
                $flights = Flight::with('subfleets', 'subfleets.aircraft', 'airline')->whereHas('subfleets', function ($query) use ($subfleet) {
                    $query->where(['subfleets.id' => $subfleet, 'visible' => true]);
                })->take(100)->get();
            } else {
                $flights = Flight::where($query)->with('subfleets', 'subfleets.aircraft', 'airline')->whereHas('subfleets', function ($query) use ($subfleet) {
                    $query->where(['subfleets.id' => $subfleet, 'visible' => true]);
                })->take(100)->get();
            }
        } else {
            if (empty($query)) {
                $flights = Flight::with('subfleets', 'subfleets.aircraft', 'airline')->where('visible', true)->take($limit)->get();
            } else {
                $flights = Flight::where($query)->with('subfleets', 'subfleets.aircraft', 'airline')->where('visible', true)->take($limit)->get();
            }
        }

        foreach ($flights as $flight) {
            $aircraft = [];
            $flight = $this->flightService->filterSubfleets($request->user(), $flight);
            foreach ($flight->subfleets as $subfleet) {
                foreach ($subfleet->aircraft as $acf) {
                    $aircraft[] = $acf['id'];
                }
            }
            $ft_converted = floatval(number_format($flight->flight_time / 60, 2));
            $output[] = [
                "id"               => $flight->id,
                "number"           => $flight->flight_number,
                "code"             => $flight->airline->code,
                "departureAirport" => $flight->dpt_airport_id,
                "arrivalAirport"   => $flight->arr_airport_id,
                "flightLevel"      => $flight->level,
                "distance"         => $flight->distance->local(),
                "departureTime"    => $flight->dpt_time,
                "arrivalTime"      => $flight->arr_time,
                "flightTime"       => $ft_converted,
                "daysOfWeek"       => [],
                "type"             => $this->flightType($flight->flight_type),
                "aircraft"         => sizeof($aircraft) === 1 ? $aircraft[0] : $aircraft,
                "notes"            => $flight->notes
            ];
        }

        return response()->json($output);
    }
    public function start(Request $request)
    {
        $user = Auth::user();
        $bid = Bid::find($request->input('bidID'));
        logger($request->all());
        $flight = Flight::find($bid->flight_id);
        $aircraft = null;
        if ($bid->flight->simbrief) {
            $aircraft = $bid->flight->simbrief->aircraft->id;
        } elseif ($bid->aircraft_id !== null) {
            $aircraft = $bid->aircraft_id;
        } else {
            // if no aircraft is available, return a 500 error saying no aircraft is attached to the bid
            return response()->json(['message' => 'No aircraft attached to bid'], 500);
        }

        $attrs = [
            'flight_number'    => $flight->flight_number,
            'airline_id'       => $flight->airline_id,
            'route_code'       => $flight->route_code,
            'route_leg'        => $flight->route_leg,
            'flight_type'      => $flight->flight_type,
            'dpt_airport_id'   => $flight->dpt_airport_id,
            'arr_airport_id'   => $flight->arr_airport_id,
            'planned_distance' => $flight->distance,
            'aircraft_id'      => $aircraft,
            'flight_id'        => $flight->id,
            'source'           => PirepSource::ACARS,
            'source_name'      => "smartCARS 3"
        ];
        // find if there's a SimBrief OFP for this flight and user. If so, add it to the PIREP
        $simbrief = $flight->simbrief()->where('user_id', $user->id)->first();

        if ($simbrief !== null) {
            $attrs['simbrief_id'] = $simbrief->id;
        }

        try {
            $pirep = $this->pirepService->prefile(Auth::user(), $attrs);
            $this->generateFares(Aircraft::find($aircraft), $flight, $pirep);
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
        $pirep = Pirep::find($input['uuid']);
        // if no pirep, check against the bid
        if ($pirep === null) {
            $af = ActiveFlight::where('bid_id', $input['bidID'])->first();
            if ($af === null) {
                $bid = Bid::find($request->input('bidID'));
                logger($request->all());
                $flight = Flight::find($bid->flight_id);

                $attrs = [
                    'user_id'          => Auth::user()->id,
                    'flight_number'    => $flight->flight_number,
                    'airline_id'       => $flight->airline_id,
                    'route_code'       => $flight->route_code,
                    'route_leg'        => $flight->route_leg,
                    'flight_type'      => $flight->flight_type,
                    'dpt_airport_id'   => $flight->dpt_airport_id,
                    'arr_airport_id'   => $flight->arr_airport_id,
                    'planned_distance' => $flight->distance,
                    'aircraft_id'      => $request->input('aircraft'),
                    'flight_id'        => $flight->id,
                    'state'            => PirepState::IN_PROGRESS,
                    'status'           => $this->phaseToStatus($input['phase']),
                    'source'           => PirepSource::ACARS,
                    'source_name'      => "smartCARS 3"
                ];
                $pirep = new Pirep($attrs);
                $pirep->save();
                $this->generateFares(Aircraft::find($request->input('aircraft')), $flight, $pirep);
                event(new PirepPrefiled($pirep));
                // Add new Active Flight
                ActiveFlight::create([
                    'bid_id'   => $input['bidID'],
                    'pirep_id' => $pirep->id
                ]);
                return;
            }
            $pirep = Pirep::find($af->pirep_id);
        }

        // Check if a phase has changed
        $pirep->status = $this->phaseToStatus($input['phase']);

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
            $pirep->block_on_time == Carbon::now();
        }
        $pirep->updated_at = Carbon::now();

        // Get current flight time by checking time since first ACARS telemetry report.
        $first_acars = $pirep->acars()->first();
        if ($first_acars !== null) {
            $minutes = Carbon::now()->diffInMinutes($first_acars->created_at);
            $pirep->flight_time = $minutes;
        }
        $pirep->save();
        $pirep->acars()->create([
            'status'   => $pirep->status,
            'type'     => AcarsType::FLIGHT_PATH,
            'lat'      => $input['latitude'],
            'lon'      => $input['longitude'],
            'distance' => $pirep->planned_distance->local(2) - $input['distanceRemaining'],
            'heading'  => $input['heading'],
            'altitude' => $input['altitude'],
            'gs'       => $input['groundSpeed']
        ]);
    }

    public function phaseToStatus(string $phase)
    {
        switch(strtolower($phase)) {
            case 'boarding':
                return PirepStatus::BOARDING;
            case 'push_back':
                return PirepStatus::PUSHBACK_TOW;
            case 'taxi':
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
                return PirepStatus::LANDED;
            case 'deboarding':
                return PirepStatus::ARRIVED;
            case 'diverted':
                return PirepStatus::DIVERTED;
            default:
                return null;
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

        if (setting('flights.use_cargo_load_factor ', false)) {
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
