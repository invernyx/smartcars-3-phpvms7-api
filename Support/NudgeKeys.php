<?php

namespace Modules\SmartCARS3phpVMS7Api\Support;

/**
 * The public halves of the keys smartCARS signs its update nudges with. They are public on purpose:
 * they can only verify a signature, never make one, so shipping them in the module is safe. A list,
 * so a new key can ship before the old one is retired. The same keys verify the signatures of flight
 * analyses, each purpose signing its own message form (AnalysisVerifier).
 */
class NudgeKeys
{
    public const PUBLIC_KEYS = [
        "-----BEGIN PUBLIC KEY-----\n"
        ."MIIBojANBgkqhkiG9w0BAQEFAAOCAY8AMIIBigKCAYEAuPYA7/6eAVajCDC7FxR4\n"
        ."O/yVhNIb3/F4greVIU80GDmgbjnx6v8194jvzTTDOdxK+Ug5hJweYmAS2iu61lgX\n"
        ."+PzQQU6tQsvwdMnEIYlJ5GWA4HdjqzNcmh95BuYEpLUAcgeB9cvkR+9TB6dqoR9D\n"
        ."dZdtM5ISzn0vhS+n92Of1nUitvjzbMholrl2EVRnIYFAX3c1XPu/kbuTCYSMNZeU\n"
        ."WFyKqEpqeX4bS8KrzePEawB7CIoki8UVorDushB+dJP/1x3OhChvafVKJvE4zwfm\n"
        ."VsKZWA1iNZ+R4116sQMlAz8CwYfigSJak4bOh6qjXxiZZRwMLEyDPh+U8Pr7DIuO\n"
        ."YSKUB4DQgjXiyT6v5IZU3WRYDZcMDn4tDgh3Z4oYZBt2EYVVGDfc1X0PQAXvrA86\n"
        ."D1RqLV8nOauBnipogQu2gOMbX4ZwvTmXFgjrBCPUcEyqJXe4f2VI6ir5yOKwlUrA\n"
        ."F0wCxQd+M7p81vhfFLFu2Ik6t/0xjmb0dUCMBnPEseq9AgMBAAE=\n"
        ."-----END PUBLIC KEY-----\n",
    ];

    public static function verifies(string $message, string $signatureBase64, array $publicKeys = self::PUBLIC_KEYS): bool
    {
        $signature = base64_decode($signatureBase64, true);
        if ($signature === false) {
            return false;
        }
        foreach ($publicKeys as $pem) {
            // @: Laravel turns the warning for an unparsable PEM into an exception; skip that key instead.
            $key = @openssl_pkey_get_public($pem);
            if ($key === false) {
                continue;
            }
            if (openssl_verify($message, $signature, $key, OPENSSL_ALGO_SHA256) === 1) {
                return true;
            }
        }
        return false;
    }
}
