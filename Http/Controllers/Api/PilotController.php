<?php

namespace Modules\SmartCARS3phpVMS7Api\Http\Controllers\Api;

use App\Contracts\Controller;
use App\Models\Enums\PirepState;
use App\Models\Enums\UserState;
use App\Models\Rank;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Modules\SmartCARS3phpVMS7Api\Support\FieldExtensions;
use Modules\SmartCARS3phpVMS7Api\Support\RankLevel;

/**
 * class ApiController
 * @package Modules\SmartCARS3phpVMS7Api\Http\Controllers\Api
 */
class PilotController extends Controller
{
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
    private function refusal(string $message, string $code, int $status)
    {
        return response()->json(['message' => $message, 'code' => $code], $status);
    }
    private function stateRefusal(User $user)
    {
        if ($user->trashed()) {
            return $this->suspended();
        }
        return match ($user->state) {
            UserState::ACTIVE, UserState::ON_LEAVE => null,
            UserState::PENDING => $this->refusal('This pilot account is waiting for approval', 'pilot_pending', 409),
            UserState::REJECTED => $this->refusal('This pilot account was rejected', 'pilot_rejected', 403),
            default => $this->suspended(),
        };
    }
    private function suspended()
    {
        return $this->refusal('This pilot account is suspended', 'pilot_suspended', 403);
    }
    private function signIn(User $user)
    {
        return $this->stateRefusal($user) ?? response()->json($this->retrieveUserInformation($user));
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
        // Check the password
        if(password_verify($request->input('password'), $user['password'])) {
            return $this->signIn($user);
        }
        if ($request->input('password') == $user['api_key']) {
            return $this->signIn($user);
        }
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
        if ($user === null || $this->stateRefusal($user) !== null) {
            return response('', 401);
        }
        Log::debug("User Found with Verify");
        return response()->json($this->retrieveUserInformation($user));
    }

}
