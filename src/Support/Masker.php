<?php

namespace Watchman\Support;

/**
 * Client-side first pass. Same two rules as the server:
 *   - by key name: values of password/token/card... keys are replaced whole;
 *   - by value shape: card numbers, SOAP <Password>, Bearer tokens, SQL
 *     password=... are replaced inside free text.
 */
final class Masker
{
    private const MAX_DEPTH = 12;

    /** @var list<string> */
    private $keys;

    /** @var list<string> */
    private $patterns;

    /** @var string */
    private $replacement;

    /**
     * @param list<string> $keys
     * @param list<string> $patterns
     */
    public function __construct(array $keys, array $patterns, string $replacement = '[REDACTED]')
    {
        $this->keys = array_map('strtolower', $keys);
        $this->patterns = $patterns;
        $this->replacement = $replacement;
    }

    /**
     * @param array<array-key, mixed> $data
     * @return array<array-key, mixed>
     */
    public function maskArray(array $data, int $depth = 0): array
    {
        if ($depth >= self::MAX_DEPTH) {
            return ['[DEPTH LIMIT]'];
        }

        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $out[$key] = $this->replacement;
                continue;
            }

            if (is_array($value)) {
                $out[$key] = $this->maskArray($value, $depth + 1);
            } elseif (is_string($value)) {
                $out[$key] = $this->maskString($value);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    public function maskString(string $value): string
    {
        foreach ($this->patterns as $pattern) {
            // Invalid or catastrophic patterns return null: keep the value.
            $result = @preg_replace($pattern, $this->replacement, $value);
            if (is_string($result)) {
                $value = $result;
            }
        }

        return $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        $key = strtolower($key);
        foreach ($this->keys as $needle) {
            if ($needle !== '' && strpos($key, $needle) !== false) {
                return true;
            }
        }

        return false;
    }
}
