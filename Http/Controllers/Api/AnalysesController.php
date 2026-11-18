<?php

namespace Modules\SmartCARS3phpVMS7Api\Http\Controllers\Api;

use App\Contracts\Controller;
use App\Models\Pirep;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Modules\SmartCARS3phpVMS7Api\Models\FlightAnalysis;
use Modules\SmartCARS3phpVMS7Api\Support\AnalysisVerifier;

/**
 * class AnalysesController
 * @package Modules\SmartCARS3phpVMS7Api\Http\Controllers\Api
 */
class AnalysesController extends Controller
{
    private const SEARCH_LIMIT = 100;

    private const JSON_PARTS = [
        'landingAnalysis' => 'landing_analysis',
        'flightScore'     => 'flight_score_detail',
        'approachTrail'   => 'approach_trail',
        'takeoffAnalysis' => 'takeoff_analysis',
        'takeoffTrail'    => 'takeoff_trail',
        'debrief'         => 'debrief',
    ];

    private const SIGNED_PARTS = [
        'signedLandingAnalysis' => [
            'kind'     => AnalysisVerifier::KIND_LANDING,
            'analysis' => 'landing_analysis',
            'signed'   => 'signed_landing_analysis',
            'verified' => 'landing_verified',
        ],
        'signedTakeoffAnalysis' => [
            'kind'     => AnalysisVerifier::KIND_TAKEOFF,
            'analysis' => 'takeoff_analysis',
            'signed'   => 'signed_takeoff_analysis',
            'verified' => 'takeoff_verified',
        ],
    ];

    // Every value below the limits fits decimal(8,2) and decimal(5,2); string(32) counts characters, so 32 bytes always fit.
    private const TOUCHDOWN_RATE_LIMIT = 1000000;
    private const FLIGHT_SCORE_LIMIT = 1000;
    private const LANDING_GRADE_MAX_LENGTH = 32;
    private const SUMMARY_DECIMALS = 2;

    private const JSON_FLAGS = JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    // Escaped unicode keeps the stored text ASCII, so a 3-byte utf8 connection can neither cut nor refuse it.
    private const SIGNED_JSON_FLAGS = JSON_UNESCAPED_SLASHES;

    public function upload(Request $request)
    {
        // The raw body, not $request->input(): phpVMS's TrimStrings and ConvertEmptyStringsToNull
        // middleware rewrite nested strings, and decoding to arrays would turn {} into [].
        $body = json_decode($request->getContent());
        if (!is_object($body)) {
            return response()->json(['message' => 'A JSON object body is required'], 422);
        }

        $pirepId = $body->pirepID ?? null;
        $pirep = is_string($pirepId)
            ? Pirep::where(['id' => $pirepId, 'user_id' => Auth::id()])->first()
            : null;
        if ($pirep === null) {
            return response()->json(['message' => 'PIREP not found'], 404);
        }

        $formatVersion = $body->formatVersion ?? null;
        if (!is_int($formatVersion) || $formatVersion < 0) {
            return response()->json(['message' => 'formatVersion must be an integer >= 0'], 422);
        }

        $landingAnalysis = $body->landingAnalysis ?? null;
        $flightScore = $body->flightScore ?? null;

        $values = [
            'user_id'        => Auth::id(),
            'format_version' => $formatVersion,
        ];
        foreach (self::JSON_PARTS as $key => $column) {
            $values[$column] = isset($body->{$key}) ? json_encode($body->{$key}, self::JSON_FLAGS) : null;
        }

        // A signed part never fails the upload: one that does not verify leaves the analysis sent beside it.
        foreach (self::SIGNED_PARTS as $key => $part) {
            $result = AnalysisVerifier::read($body->{$key} ?? null, $part['kind'], (string) $pirep->id, (int) Auth::id());
            $reason = $result['reason'] === null || $result['detail'] === null ? $result['reason'] : $result['reason'].': '.$result['detail'];
            $verifiedAnalysis = null;
            if ($result['analysis'] !== null) {
                try {
                    $verifiedAnalysis = json_encode($result['analysis'], self::JSON_FLAGS | JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    // A number too large for a float decodes to INF, which cannot be stored: the flag must not say verified.
                    $reason = 'error: '.get_class($e);
                }
            }
            $values[$part['signed']] = $result['signed'] === null ? null : json_encode($result['signed'], self::SIGNED_JSON_FLAGS);
            $values[$part['verified']] = $verifiedAnalysis !== null;
            if ($verifiedAnalysis !== null) {
                $values[$part['analysis']] = $verifiedAnalysis;
                if ($part['kind'] === AnalysisVerifier::KIND_LANDING) {
                    $landingAnalysis = $result['analysis'];
                }
            }
            if ($reason !== null) {
                Log::warning('smartCARS: the signed '.$part['kind'].' analysis of PIREP '.$pirep->id.' is not verified ('.$reason.')');
            }
        }

        $values['touchdown_rate_fpm'] = self::summaryNumber(data_get($landingAnalysis, 'touchdown.rateFpm'), self::TOUCHDOWN_RATE_LIMIT);
        $values['landing_grade'] = self::summaryText(data_get($landingAnalysis, 'overall'), self::LANDING_GRADE_MAX_LENGTH);
        $values['flight_score'] = self::summaryNumber(data_get($flightScore, 'blended'), self::FLIGHT_SCORE_LIMIT);

        FlightAnalysis::updateOrCreate(['pirep_id' => $pirep->id], $values);

        return response()->json(['status' => 200]);
    }

    public function search(Request $request)
    {
        $pirepIds = $request->input('pirepIDs');
        if (!is_array($pirepIds)) {
            return response()->json(['message' => 'pirepIDs is required'], 422);
        }
        $pirepIds = array_values(array_filter($pirepIds, 'is_string'));
        if (count($pirepIds) > self::SEARCH_LIMIT) {
            return response()->json(['message' => 'At most ' . self::SEARCH_LIMIT . ' pirepIDs are allowed'], 422);
        }

        $rows = FlightAnalysis::whereIn('pirep_id', $pirepIds)
            ->where('user_id', Auth::id())
            ->get();

        return response()->json($rows->map(function ($row) {
            $result = [
                'pirepID'       => $row->pirep_id,
                'formatVersion' => $row->format_version,
            ];
            foreach (self::JSON_PARTS as $key => $column) {
                $raw = $row->getRawOriginal($column);
                $result[$key] = $raw === null ? null : json_decode($raw);
            }
            foreach (self::SIGNED_PARTS as $key => $part) {
                $raw = $row->getRawOriginal($part['signed']);
                $result[$key] = $raw === null ? null : json_decode($raw);
            }
            return $result;
        })->values(), 200, [], self::JSON_FLAGS);
    }

    // Rounded to the column's scale before the comparison and stored rounded: Laravel binds a float with
    // 14 significant digits, so an unrounded value that passed could still reach the limit in the database.
    private static function summaryNumber(mixed $value, float $limit): ?float
    {
        if (!is_int($value) && !(is_float($value) && is_finite($value))) {
            return null;
        }
        $rounded = round($value, self::SUMMARY_DECIMALS);
        return abs($rounded) < $limit ? $rounded : null;
    }

    private static function summaryText(mixed $value, int $maxLength): ?string
    {
        return is_string($value) && strlen($value) <= $maxLength ? $value : null;
    }
}
