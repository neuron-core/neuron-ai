<?php

declare(strict_types=1);

namespace NeuronAI;

use function bin2hex;
use function chr;
use function explode;
use function microtime;
use function ord;
use function pack;
use function random_bytes;
use function str_split;
use function substr;
use function vsprintf;

/**
 * RFC 9562 UUIDv7: a 48-bit Unix timestamp in milliseconds followed by 74 random bits,
 * so IDs sort by creation time to the millisecond. Generation keeps no state: forked
 * children and uncoordinated workers cannot repeat each other's IDs.
 */
class UniqueIdGenerator
{
    public static function generateId(string $prefix = ''): string
    {
        return $prefix . self::generateUUID();
    }

    public static function generateUUID(): string
    {
        $bytes = substr(pack('J', self::currentMilliseconds()), 2) . random_bytes(10);
        $bytes[6] = chr(0x70 | (ord($bytes[6]) & 0x0F)); // version 7
        $bytes[8] = chr(0x80 | (ord($bytes[8]) & 0x3F)); // variant 10

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * Read from microtime()'s string form: the float form can round down across a
     * millisecond boundary.
     */
    protected static function currentMilliseconds(): int
    {
        [$fraction, $seconds] = explode(' ', microtime());

        return (int) ($seconds . substr($fraction, 2, 3));
    }
}
