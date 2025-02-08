<?php

namespace Modules\SmartCARS3phpVMS7Api\Http\Controllers\Api;

use App\Contracts\Controller;
use App\Models\Enums\PirepState;
use App\Models\Pirep;
use App\Models\PirepComment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Modules\SmartCARS3phpVMS7Api\Models\PirepLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * class ApiController
 * @package Modules\SmartCARS3phpVMS7Api\Http\Controllers\Api
 */
class PirepsController extends Controller
{
    /**
     * Just send out a message
     *
     * @param Request $request
     *
     * @return mixed
     */
    public function details(Request $request)
    {
        $pirepID = $request->get('id');
        $user_id = $request->get('pilotID');

        $pirep = Pirep::find($pirepID);
        $pirep->load('comments', 'acars_logs', 'acars');

        return response()->json([
            'flightLog' => $pirep->comments->map(function ($a ) { return $a->comment;}),
            'locationData' => $this->getLocationData($pirep, $user_id),
            'flightData' => $this->getFlightData($pirep, $user_id)
        ]);
    }

    private function getFlightData($pirep, $user_id) {
        if (Schema::hasTable('smartCARS3_FlightData')) {
            $flightData = DB::table("smartCARS3_FlightData")->select('log')->where('pilotID', $user_id)->where('pirepID', $pirep->id)->get();
            if (count($flightData) > 0) {
                return json_decode(gzdecode($flightData[0]->log));
            }
        }
        $pirep->load('comments', 'acars_logs', 'acars');

        $flightData = [];
        $i = 0;
        foreach ($pirep->acars_logs->sortBy('created_at') as $acars_log) {
            $flightData[] = [
                'eventId' => $acars_log->id,
                'eventTimestamp' => $acars_log->created_at,
                'eventElapsedTime' => $i,
                'eventCondition' => null,
                'message' => $acars_log->log
            ];
        }
        return $flightData;
    }

    private function getLocationData($pirep, $user_id) {
        if (Schema::hasTable('smartCARS3_FlightData')) {
            $flightData = DB::table("smartCARS3_FlightData")->select('locations')->where('pilotID', $user_id)->where('pirepID', $pirep->id)->get();
            if (count($flightData) > 0) {
                return json_decode(gzdecode($flightData[0]->locations));
            }
        }
        $pirep->load('comments', 'acars_logs', 'acars');

        $locationData = [];
        foreach ($pirep->acars->sortBy('created_at') as $acars) {
            $locationData[] = [
                'latitude' => $acars->lat,
                'longitude' => $acars->lon,
                'heading' => $acars->heading
            ];
        }
        return $locationData;
    }

    /**
     * Handles /search
     *
     * @param Request $request
     *
     * @return mixed
     */
    public function search(Request $request)
    {
        $user = User::find($request->get('pilotID'));
        $user->load('pireps', 'pireps.airline');
        $output_pireps = [];
        foreach ($user->pireps->sortByDesc('created_at') as $pirep) {
            $output_pireps[] = [
                'id' => $pirep->id,
                'submitDate' => Carbon::createFromTimeString($pirep->submitted_at)->toDateString(),
                'airlineCode' => $pirep->airline->icao,
                'route' => [],
                'number' => $pirep->flight_number,
                'distance' => $pirep->planned_distance->getResponseUnits()['mi'],
                'flightType' => $pirep->flight_type,
                'departureAirport' => $pirep->dpt_airport_id,
                'arrivalAirport' => $pirep->arr_airport_id,
                'aircraft' => $pirep->aircraft_id,
                'status' => self::getStatus($pirep->state),
                'flightTime' => $pirep->flight_time / 60,
                'landingRate' => $pirep->landing_rate,
                'fuelUsed' => $pirep->fuel_used->getResponseUnits()['lbs']
            ];
        }
        return response()->json($output_pireps);
    }

    /**
     * Handles /latest
     *
     * @param Request $request
     *
     * @return mixed
     */
    public function latest(Request $request)
    {
        $user = $user = Auth::user();
        $pirep = $user->latest_pirep;

        return response()->json([
            'id' => $pirep->id,
            'submitDate' => Carbon::createFromTimeString($pirep->submitted_at)->toDateString(),
            'airlineCode' => $pirep->airline->icao,
            'route' => [],
            'number' => $pirep->flight_number,
            'distance' => $pirep->planned_distance->getResponseUnits()['mi'],
            'flightType' => $pirep->flight_type,
            'departureAirport' => $pirep->dpt_airport_id,
            'arrivalAirport' => $pirep->arr_airport_id,
            'aircraft' => $pirep->aircraft_id,
            'status' => self::getStatus($pirep->state),
            'flightTime' => $pirep->flight_time / 60,
            'landingRate' => $pirep->landing_rate,
            'fuelUsed' => $pirep->fuel_used->getResponseUnits()['lbs']
        ]);
    }

    function getStatus($value) {
        switch(intval($value)) {
            case 1:
                return 'Pending';
                break;
            case 2:
                return 'Accepted';
                break;
            case 6:
                return 'Rejected';
                break;
            default:
                return;
                break;
        }
    }
}
