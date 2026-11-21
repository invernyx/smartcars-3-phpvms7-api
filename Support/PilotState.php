<?php

namespace Modules\SmartCARS3phpVMS7Api\Support;

use App\Models\Enums\UserState;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class PilotState
{
    public static function refusal(User $user): ?JsonResponse
    {
        if ($user->trashed()) {
            return self::suspended();
        }
        return match ($user->state) {
            UserState::ACTIVE, UserState::ON_LEAVE => null,
            UserState::PENDING => self::refusalResponse('This pilot account is waiting for approval', 'pilot_pending', 409),
            UserState::REJECTED => self::refusalResponse('This pilot account was rejected', 'pilot_rejected', 403),
            default => self::suspended(),
        };
    }

    private static function suspended(): JsonResponse
    {
        return self::refusalResponse('This pilot account is suspended', 'pilot_suspended', 403);
    }

    public static function refusalResponse(string $message, string $code, int $status): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => $code], $status);
    }
}
