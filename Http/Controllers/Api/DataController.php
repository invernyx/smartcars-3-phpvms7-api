<?php

namespace Modules\SmartCARS3phpVMS7Api\Http\Controllers\Api;

use App\Contracts\Controller;
use App\Models\Aircraft;
use App\Models\Airport;
use App\Models\Enums\AircraftState;
use App\Models\Enums\AircraftStatus;
use App\Models\Enums\FlightType;
use App\Models\News;
use App\Models\Subfleet;
use Illuminate\Http\Request;

/**
 * class ApiController
 * @package Modules\SmartCARS3phpVMS7Api\Http\Controllers\Api
 */
class DataController extends Controller
{
    /**
     * Just send out a message
     *
     * @param Request $request
     *
     * @return mixed
     */
    public function aircraft(Request $request)
    {

        $aircraft = Aircraft::all();
        $output = [];
        foreach ($aircraft as $item) {
            $state = "";
            switch ($item->state) {
                case AircraftState::PARKED:
                    $state = "Available";
                    break;
                case AircraftState::IN_USE:
                    $state = "In Use";
                    break;
                case AircraftState::IN_AIR:
                    $state = "In Air";
                    break;
            }
            $output[] = [
                "id" => $item->id,
                "code" => $item->icao,
                "name" => "{$item->name} ({$item->registration}) | ".AircraftStatus::label($item->status),
                "status" => AircraftStatus::label($item->status),
                "serviceCeiling" => "40000",
                "maximumPassengers" => 300,
                "maximumCargo" => 1000,
                "minimumRank" => 0
            ];
        }
        return response()->json($output);
    }
    public function subfleets(Request $request)
    {
        return response()->json(Subfleet::with('airline')->get());
    }
    public function airports(Request $request)
    {
        $airports = Airport::get()->map(function($apt) {
            return [
                'id' => $apt->id,
                'code' => $apt->icao,
                'name' => $apt->name,
                'latitude' => $apt->lat,
                'longitude' => $apt->lon
            ];
        });
        return response()->json($airports);
    }
    public function news(Request $request)
    {
        $news = News::latest()->first();
        return response()->json([
            'title' => $news->subject,
            'body' => $news->body,
            'postedAt' => $news->created_at,
            'postedBy' => "Admin"
        ]);
    }
    public function flightTypes()
    {
        return response()->json(FlightType::toArray());
    }

}
