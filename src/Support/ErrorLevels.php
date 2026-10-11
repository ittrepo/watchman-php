<?php

namespace Watchman\Support;

/**
 * Maps PHP errno values to Watchman event type + level.
 *
 * Numeric literals instead of constants where a constant is deprecated
 * (E_STRICT warns on PHP 8.4) or absent on old versions.
 */
final class ErrorLevels
{
    public const FATAL_TYPES = [
        1,    // E_ERROR
        4,    // E_PARSE
        16,   // E_CORE_ERROR
        64,   // E_COMPILE_ERROR
    ];

    /** @return array{0: string, 1: string} [type, level] */
    public static function forErrno(int $errno): array
    {
        switch ($errno) {
            case 1:      // E_ERROR
            case 4:      // E_PARSE
            case 16:     // E_CORE_ERROR
            case 64:     // E_COMPILE_ERROR
                return ['PHP_FATAL', 'CRITICAL'];
            case 256:    // E_USER_ERROR
            case 4096:   // E_RECOVERABLE_ERROR
                return ['PHP_ERROR', 'ERROR'];
            case 2:      // E_WARNING
            case 32:     // E_CORE_WARNING
            case 128:    // E_COMPILE_WARNING
                return ['PHP_WARNING', 'WARNING'];
            case 512:    // E_USER_WARNING
                return ['PHP_USER_WARNING', 'WARNING'];
            case 8:      // E_NOTICE
                return ['PHP_NOTICE', 'NOTICE'];
            case 1024:   // E_USER_NOTICE
                return ['PHP_USER_NOTICE', 'NOTICE'];
            case 8192:   // E_DEPRECATED
            case 16384:  // E_USER_DEPRECATED
                return ['PHP_DEPRECATED', 'INFO'];
            case 2048:   // E_STRICT
                return ['PHP_STRICT', 'INFO'];
            default:
                return ['PHP_ERROR', 'ERROR'];
        }
    }
}
