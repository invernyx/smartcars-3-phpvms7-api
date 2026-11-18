<?php

namespace Modules\SmartCARS3phpVMS7Api\Support;

/**
 * The extensions object carrying a flight's or a pilot's phpVMS custom field values.
 */
class FieldExtensions
{
    public const MAX_BYTES = 16384;

    /**
     * @param iterable<array{0: string, 1: mixed}> $namedValues name and value pairs
     */
    public static function fromNamedValues(iterable $namedValues): ?array
    {
        $fields = [];
        foreach ($namedValues as [$name, $value]) {
            if ($value === null || $value === '') {
                continue;
            }
            $fields[$name] = $value;
        }
        if (empty($fields)) {
            return null;
        }
        // An object even when every field name is numeric, which json_encode would turn into a list
        $extensions = ['fields' => (object) $fields];
        $json = json_encode($extensions);
        return $json === false || strlen($json) > self::MAX_BYTES ? null : $extensions;
    }
}
