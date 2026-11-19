<?php

namespace Modules\SmartCARS3phpVMS7Api\Http\Controllers\Api;

use App\Contracts\Controller;
use Illuminate\Http\Request;
use Modules\SmartCARS3phpVMS7Api\Jobs\AutoUpdate;
use Modules\SmartCARS3phpVMS7Api\Support\AutoUpdateSettings;
use Modules\SmartCARS3phpVMS7Api\Support\NudgeCooldown;
use Modules\SmartCARS3phpVMS7Api\Support\NudgeKeys;

/**
 * Receives the update nudges smartCARS signs. The request is only a nudge: the job installs the
 * latest release, fetched itself from a fixed repository, so a caller can never pick the code.
 * @package Modules\SmartCARS3phpVMS7Api\Http\Controllers\Api
 */
class AutoUpdateController extends Controller
{
    private const MAX_CLOCK_SKEW_SEC = 300;
    private const MAX_TAG_LENGTH = 100;

    public function receive(Request $request)
    {
        if (!AutoUpdateSettings::isEnabled()) {
            return response()->json(['message' => 'Auto-update is disabled'], 200);
        }

        $timestamp = (string) $request->header('X-SmartCARS-Timestamp', '');
        // All digits, never looser: the same key signs flight analyses as smartcars-analysis-v1.<payload>,
        // so a timestamp that could hold other characters would let such a signature pass as a nudge.
        if (!preg_match('/^\d{1,12}\z/', $timestamp) || abs(time() - (int) $timestamp) > self::MAX_CLOCK_SKEW_SEC) {
            return response()->json(['message' => 'Invalid or stale timestamp'], 401);
        }

        $signature = (string) $request->header('X-SmartCARS-Signature', '');
        // The raw body: smartCARS signs the exact bytes it sent, which $request->input() would not reproduce.
        if (!NudgeKeys::verifies($timestamp.'.'.$request->getContent(), $signature)) {
            return response()->json(['message' => 'Invalid signature'], 401);
        }

        if (NudgeCooldown::isActive()) {
            return response()->json(['message' => 'Auto-update was requested less than an hour ago'], 429);
        }
        NudgeCooldown::start();

        $payload = json_decode($request->getContent(), true);
        $tag = is_array($payload) ? ($payload['tag'] ?? null) : null;
        if (!is_string($tag) || strlen($tag) > self::MAX_TAG_LENGTH) {
            $tag = null;
        }

        // phpVMS ships with the sync queue: run after the response then, so the sender does not wait for the update.
        if (config('queue.default') === 'sync') {
            AutoUpdate::dispatchAfterResponse($tag);
        } else {
            AutoUpdate::dispatch($tag);
        }

        return response()->json(['message' => 'Update queued'], 202);
    }
}
