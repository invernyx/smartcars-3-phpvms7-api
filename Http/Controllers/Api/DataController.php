<?php

namespace Modules\SmartCARS3phpVMS7Api\Http\Controllers\Api;

use App\Contracts\Controller;
use App\Models\Aircraft;
use App\Models\Airport;
use App\Models\Enums\AircraftState;
use App\Models\Enums\AircraftStatus;
use App\Models\Enums\FareType;
use App\Models\News;
use App\Models\Subfleet;
use App\Services\FareService;
use App\Services\UserService;
use Illuminate\Http\Request;
use Modules\SmartCARS3phpVMS7Api\Support\FreeFlightPlaceholders;
use Modules\SmartCARS3phpVMS7Api\Support\RankLevel;
use Modules\SmartCARS3phpVMS7Api\Support\ScSettings;

/**
 * class ApiController
 * @package Modules\SmartCARS3phpVMS7Api\Http\Controllers\Api
 */
class DataController extends Controller
{
    private const AIRCRAFT_STATES = [
        AircraftState::PARKED => 'parked',
        AircraftState::IN_USE => 'in_use',
        AircraftState::IN_AIR => 'in_air',
    ];
    // Only a parked aircraft can be prefiled, so a state phpVMS may add later reads as unavailable
    private const AIRCRAFT_STATE_UNKNOWN = 'in_use';

    private function jsonWithEtag(Request $request, $payload)
    {
        $response = response()->json($payload);
        $response->setEtag(sha1($response->getContent()));
        $response->isNotModified($request);
        return $response;
    }

    private function restrictionsFor($user): array
    {
        $restrictRank = setting('pireps.restrict_aircraft_to_rank', true);
        $restrictType = setting('pireps.restrict_aircraft_to_typerating', false);

        $rankSubfleetIds = $restrictRank && $user->rank !== null
            ? $user->rank->subfleets()->pluck('id')->toArray()
            : [];
        // getAllowableSubfleets dereferences the rank when rank restriction is on, so a rankless pilot is allowed nothing
        $allowedSubfleetIds = $restrictRank && $user->rank === null
            ? []
            : app(UserService::class)->getAllowableSubfleets($user)->pluck('id')->toArray();

        return [$restrictRank, $restrictType, array_map('intval', $rankSubfleetIds), array_map('intval', $allowedSubfleetIds)];
    }

    private function subfleetValues(array $subfleetIds): array
    {
        $fareService = app(FareService::class);
        $levels = RankLevel::levels();
        $values = [];
        foreach (Subfleet::withTrashed()->with(['fares', 'ranks'])->whereIn('id', $subfleetIds)->get() as $subfleet) {
            $passengers = 0;
            $cargo = 0;
            foreach ($fareService->getForSubfleet($subfleet) as $fare) {
                if ((int) $fare->type === FareType::PASSENGER) {
                    $passengers += (int) $fare->capacity;
                } elseif ((int) $fare->type === FareType::CARGO) {
                    $cargo += (int) $fare->capacity;
                }
            }
            $rankLevels = $subfleet->ranks->map(fn ($rank) => $levels[$rank->id] ?? 0);
            $values[$subfleet->id] = [
                'maximumPassengers' => $passengers,
                'maximumCargo' => $cargo,
                'minimumRank' => $rankLevels->isEmpty() ? null : $rankLevels->min(),
            ];
        }
        return $values;
    }

    /**
     * Just send out a message
     *
     * @param Request $request
     *
     * @return mixed
     */
    public function aircraft(Request $request)
    {

        $aircraft = Aircraft::orderBy('name')->get();
        [$restrictRank, $restrictType, $rankSubfleetIds, $allowedSubfleetIds] = $this->restrictionsFor($request->user());
        $subfleets = $this->subfleetValues($aircraft->pluck('subfleet_id')->filter()->unique()->values()->all());
        $output = [];
        $placeholderAircraftId = FreeFlightPlaceholders::existingAircraftId();
        foreach ($aircraft as $item) {
            if ((int) $item->id === $placeholderAircraftId) {
                continue;
            }
            $state = self::AIRCRAFT_STATES[$item->state] ?? self::AIRCRAFT_STATE_UNKNOWN;
            $subfleet = $subfleets[$item->subfleet_id] ?? null;
            $restriction = null;
            if (!in_array($item->subfleet_id, $allowedSubfleetIds, true)) {
                $isRankExcluded = $restrictRank && !in_array($item->subfleet_id, $rankSubfleetIds, true);
                $restriction = $restrictType && !$isRankExcluded ? "type_rating" : "rank";
            }
            $output[] = [
                "id" => $item->id,
                "code" => $item->icao,
                "name" => ScSettings::aircraftName($item),
                "registration" => $item->registration ?: null,
                "status" => AircraftStatus::label($item->status),
                "serviceCeiling" => null,
                "maximumPassengers" => $subfleet['maximumPassengers'] ?? 0,
                "maximumCargo" => $subfleet['maximumCargo'] ?? 0,
                "minimumRank" => $subfleet['minimumRank'] ?? null,
                "restriction" => $restriction,
                "airport" => $item->airport_id,
                "state" => $state
            ];
        }
        return $this->jsonWithEtag($request, $output);
    }
    public function airports(Request $request)
    {
        // The placeholder airport is no place to pick: it sits at 0, 0 and only stands for an unknown one
        $airports = Airport::withTrashed()->where('id', '!=', FreeFlightPlaceholders::AIRPORT_ID)->get()->map(function($apt) {
            return [
                'id' => $apt->id,
                'code' => $apt->icao,
                'name' => $apt->name,
                'latitude' => $apt->lat,
                'longitude' => $apt->lon
            ];
        });
        return $this->jsonWithEtag($request, $airports);
    }
    public function news(Request $request)
    {
        $news = News::latest()->first();
        if ($news === null) {
            return response()->json(['title' => null, 'body' => null, 'postedAt' => null, 'postedBy' => null]);
        }
        return response()->json([
            'title' => $news->subject,
            'body' => $news->body,
            'postedAt' => $news->created_at,
            'postedBy' => $news->user()->withTrashed()->first()?->name_private
        ]);
    }

}
