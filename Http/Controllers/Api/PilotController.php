<?php

namespace Modules\SmartCARS3phpVMS7Api\Http\Controllers\Api;

use App\Contracts\Controller;
use App\Models\Enums\PirepState;
use App\Models\Rank;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Modules\SmartCARS3phpVMS7Api\Support\FieldExtensions;
use Modules\SmartCARS3phpVMS7Api\Support\PilotState;
use Modules\SmartCARS3phpVMS7Api\Support\RankLevel;

/**
 * class ApiController
 * @package Modules\SmartCARS3phpVMS7Api\Http\Controllers\Api
 */
class PilotController extends Controller
{
    private const LOGIN_MAX_FAILED_ATTEMPTS = 10;
    private const LOGIN_FAILED_ATTEMPTS_DECAY_SECONDS = 60;
    private const LOGIN_LIMITER_PREFIX = 'smartcars-login:';

    private function retrieveUserInformation(User $user)
    {

        $name = explode(' ', $user['name']);
        if (count($name) <= 1) {
            $first = $name[0];
            $last = "";
        } else {
            $first = $name[0];
            $last = $name[1];
        }

        $rank = $user->rank;

        $information = [
            'dbID'      => $user->id,
            'pilotID'   => $user->ident,
            'firstName' => $first,
            'lastName'  => $last,
            'email'     => $user->email,
            'rank'      => $rank->name ?? '',
            'rankImage' => $rank?->image_url,
            'rankLevel' => RankLevel::of($rank),
            'avatar'    => $user->resolveAvatarUrl(),
            'session'   => $user->api_key
        ];
        $extensions = $this->pilotExtensions($user);
        if ($extensions !== null) {
            $information['extensions'] = $extensions;
        }
        return $information;
    }
    private function signIn(User $user)
    {
        return PilotState::refusal($user) ?? response()->json($this->retrieveUserInformation($user));
    }
    private function isPasswordOrApiKey(User $user, $password): bool
    {
        if (!is_string($password) || $password === '') {
            return false;
        }
        if (password_verify($password, $user['password'])) {
            return true;
        }
        return is_string($user['api_key']) && $user['api_key'] !== '' && hash_equals($user['api_key'], $password);
    }
    private function userBySession(Request $request): ?User
    {
        $session = $request->input('session');
        // A missing session would match a pilot without an API key (where api_key is null)
        if (!is_string($session) || $session === '') {
            return null;
        }
        try {
            return User::withTrashed()->where('api_key', $session)->with('airline', 'rank')->firstOrFail();
        } catch (ModelNotFoundException $e) {
            return null;
        }
    }
    private function pilotExtensions(User $user): ?array
    {
        $namedValues = $user->fields()->with('field')->get()
            // Internal fields hold module data, not the pilot's own
            ->filter(fn ($fieldValue) => $fieldValue->field !== null && $fieldValue->field->active && !$fieldValue->field->internal)
            ->map(fn ($fieldValue) => [$fieldValue->field->name, $fieldValue->value]);
        return FieldExtensions::fromNamedValues($namedValues);
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
            $user = User::withTrashed()->where('email', $request->query('username'))->with('airline', 'rank')->first();
        } else {
            $user = User::withTrashed()->where('pilot_id', $request->query('username'))->with('airline', 'rank')->first();
        }
        if (is_null($user)) {
            return response()->json(['message' => 'The username or password is incorrect'], 401);
        }
        $limiterKey = self::LOGIN_LIMITER_PREFIX.$user->id;
        if (RateLimiter::tooManyAttempts($limiterKey, self::LOGIN_MAX_FAILED_ATTEMPTS)) {
            return PilotState::refusalResponse('Too many sign-in attempts, try again in a minute', 'too_many_attempts', 429);
        }
        if ($this->isPasswordOrApiKey($user, $request->input('password'))) {
            RateLimiter::clear($limiterKey);
            return $this->signIn($user);
        }
        RateLimiter::hit($limiterKey, self::LOGIN_FAILED_ATTEMPTS_DECAY_SECONDS);
        return response()->json(['message' => 'The username or password is incorrect'], 401);
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

        $user = $this->userBySession($request);
        if ($user === null) {
            return response()->json(['message' => 'The session is not valid'], 401);
        }
        return $this->signIn($user);

    }
    public function statistics(Request $request)
    {
        //dd(true);
        $user = User::where('id', Auth::user()->id)->first();
        $accepted = $user->pireps()->where('state', PirepState::ACCEPTED)->get();
        return response()->json([
            'hoursFlown'         => $accepted->sum('flight_time') / 60,
            'flightsFlown'       => $accepted->count(),
            'averageLandingRate' => $accepted->avg('landing_rate') ?? 0,
            'pirepsFiled'        => $accepted->count(),
        ]);
    }
    /**
     * Called by the smartCARS servers, which read only the body: any JSON body counts as a valid session.
     * So an unknown session and a pilot who may not fly both answer an empty body, never a JSON error.
     */
    public function verify(Request $request)
    {
        $user = $this->userBySession($request);
        if ($user === null || PilotState::refusal($user) !== null) {
            return response('', 401);
        }
        Log::debug("User Found with Verify");
        return response()->json($this->retrieveUserInformation($user));
    }

}
