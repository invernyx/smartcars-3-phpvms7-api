<?php

namespace Modules\SmartCARS3phpVMS7Api\Support;

/**
 * Reads a take-off or landing analysis smartCARS signed. A verified payload proves that smartCARS
 * computed this analysis for this kind and tracking id and that it had not been altered when it was
 * uploaded; the check runs once, at upload, and does not cover later edits to the database. Its
 * community id is not checked: the module does not know its own TFDi community id.
 */
class AnalysisVerifier
{
    public const SIGNATURE_PREFIX = 'smartcars-analysis-v1.';
    public const PAYLOAD_VERSION = 1;
    public const KIND_LANDING = 'landing';
    public const KIND_TAKEOFF = 'takeoff';

    private const AIRLINE_PILOT = 'airline';

    /**
     * analysis is not null only when every check passed; reason is the first check that failed, null
     * when verified or when no part was sent; detail is the class of what was thrown when reason is error.
     *
     * @return array{signed: ?array{payload: string, signature: string}, analysis: ?object, reason: ?string, detail: ?string}
     */
    public static function read(mixed $signed, string $kind, string $pirepId, int $pilotId, array $publicKeys = NudgeKeys::PUBLIC_KEYS): array
    {
        if ($signed === null) {
            return self::result(null, null, null);
        }

        $payload = is_object($signed) ? ($signed->payload ?? null) : null;
        $signature = is_object($signed) ? ($signed->signature ?? null) : null;
        if (!is_string($payload) || $payload === '' || !is_string($signature) || $signature === '') {
            return self::result(null, null, 'envelope');
        }
        $envelope = ['payload' => $payload, 'signature' => $signature];

        try {
            // The text exactly as received: re-serializing it would not reproduce the signed bytes.
            if (!NudgeKeys::verifies(self::SIGNATURE_PREFIX.$payload, $signature, $publicKeys)) {
                return self::result($envelope, null, 'signature');
            }

            $decoded = json_decode($payload);
            if (!is_object($decoded)) {
                return self::result($envelope, null, 'payload');
            }

            $version = $decoded->version ?? null;
            if (!is_int($version) || $version !== self::PAYLOAD_VERSION) {
                return self::result($envelope, null, 'version');
            }

            $payloadKind = $decoded->kind ?? null;
            if (!is_string($payloadKind) || $payloadKind !== $kind) {
                return self::result($envelope, null, 'kind');
            }

            $trackingId = $decoded->trackingId ?? null;
            if (!is_string($trackingId) || $trackingId !== $pirepId) {
                return self::result($envelope, null, 'tracking_id');
            }

            // A pilot of any other kind (a TFDi account) has an id this backend cannot map to its pilots.
            $pilot = $decoded->pilot ?? null;
            if (is_object($pilot) && ($pilot->kind ?? null) === self::AIRLINE_PILOT) {
                $payloadPilotId = $pilot->id ?? null;
                if (!is_int($payloadPilotId) || $payloadPilotId !== $pilotId) {
                    return self::result($envelope, null, 'pilot');
                }
            }

            $analysis = $decoded->analysis ?? null;
            if (!is_object($analysis)) {
                return self::result($envelope, null, 'empty');
            }

            return self::result($envelope, $analysis, null);
        } catch (\Throwable $e) {
            // The class only: a message could quote the payload.
            return self::result($envelope, null, 'error', get_class($e));
        }
    }

    private static function result(?array $signed, ?object $analysis, ?string $reason, ?string $detail = null): array
    {
        return ['signed' => $signed, 'analysis' => $analysis, 'reason' => $reason, 'detail' => $detail];
    }
}
