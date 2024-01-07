<?php

namespace Modules\SmartCARS3phpVMS7Api\Http\Controllers\Api;

use App\Contracts\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * class ApiController
 * @package Modules\SmartCARS3phpVMS7Api\Http\Controllers\Api
 */
class PilotController extends Controller
{
    private function retrieveUserInformation($user) {

        $pilotIDSetting = setting('pilots_id_length', 4);
        $avatar = null; //$user->gravatar(38);

        $name = explode(' ', $user['name']);
        if (count($name) <= 1) {
            $first = $name[0];
            $last = "";
        } else {
            $first = $name[0];
            $last = $name[1];
        }
        return [
            'dbID' => $user['id'],
            'pilotID' => $user['airline']['icao'] . str_pad($user['pilot_id'], $pilotIDSetting, "0", STR_PAD_LEFT),
            'firstName' => $first,
            'lastName' => $last,
            'email' => $user['email'],
            'rank' => $user['rank']['name'],
            'rankImage' => null, // TODO: Add Rank Image
            'rankLevel' => 0,
            'avatar' => $avatar, // TODO: Add Avatar URL
            'session' => $user['api_key']
        ];
    }
    /**
     * Just send out a message
     *
     * @param Request $request
     *
     * @return mixed
     */
    public function login(Request $request)
    {
        //return response()->json(true);
        // Get the User
        if (str_contains($request->query('username'), '@')) {
            $user = User::where('email', $request->query('username'))->with('airline', 'rank')->first();
        } else {
            $user = User::where('pilot_id', $request->query('username'))->with('airline', 'rank')->first();
        }
        if (is_null($user)) {
            return response()->json(['message' => 'The username or password is incorrect'], 401);
        }
        // Check the password
        if(!password_verify($request->input('password'), $user['password'])) {
            return response()->json(['message' => 'The username or password is incorrect'], 401);
        }
        return response()->json($this->retrieveUserInformation($user));
    }

    /**
     * Handles /hello
     *
     * @param Request $request
     *
     * @return mixed
     */
    public function resume(Request $request)
    {

        $user = User::where('api_key', $request->input('session'))->with('airline','rank')->firstOrFail();
        // Success if user found

        return response()->json($this->retrieveUserInformation($user));

    }
    public function statistics(Request $request)
    {
        //dd(true);
        $user = User::where('id', Auth::user()->id)->with('pireps')->first();
        return response()->json([
            'hoursFlown' => $user->flight_time  / 60,
            'flightsFlown' => $user->pireps->count(),
            'averageLandingRate' => $user->pireps->avg('landing_rate'),
            'pirepsFiled' => $user->pireps->count(),
        ]);
    }
    public function verify(Request $request)
    {
        $user = User::where('api_key', $request->input('session'))->with('airline','rank')->firstOrFail();
        Log::debug("User Found with Verify");
        // Success if user found
        //dd($request);
        return response()->json($this->retrieveUserInformation($user));
    }

}
