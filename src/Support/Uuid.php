<?php

namespace Watchman\Support;

final class Uuid
{
    /** RFC 4122 version 4. */
    public static function v4(): string
    {
        try {
            $bytes = random_bytes(16);
        } catch (\Throwable $e) {
            $bytes = '';
            for ($i = 0; $i < 16; $i++) {
                $bytes .= chr(mt_rand(0, 255));
            }
        }

        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
