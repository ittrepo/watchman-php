<?php

namespace Watchman;

/**
 * SDK options with safe defaults. Unknown keys are ignored.
 *
 * Only `project` is required: it names the spool sub-folder, and bin/ship.php
 * maps it to an API key. The SDK itself never holds the key.
 */
final class Config
{
    /** @var array<string, mixed> */
    private $values;

    /** @param array<string, mixed> $options */
    public function __construct(array $options = [])
    {
        $this->values = array_replace(self::defaults(), $options);

        $this->values['project'] = self::slug((string) ($this->values['project'] ?? ''));
        $this->values['spool_dir'] = rtrim((string) $this->values['spool_dir'], '/\\');

        if (is_string($this->values['app_root']) && $this->values['app_root'] !== '') {
            $this->values['app_root'] = rtrim(str_replace('\\', '/', $this->values['app_root']), '/').'/';
        } else {
            $this->values['app_root'] = null;
        }
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'enabled' => true,
            'project' => '',
            'environment' => 'production',
            'release' => null,
            // Files under this path are "in app" (and shown relative to it);
            // vendor/ below it is not.
            'app_root' => null,
            // Paths (relative to app_root) that are app code but not where bugs
            // live: DB wrappers, logging helpers. Marked not-in-app, so grouping,
            // culprit and source context point at the caller instead.
            'in_app_exclude' => [],

            'spool_dir' => '/var/spool/watchman',
            // Present => the SDK does nothing at all: no handlers, no I/O.
            'kill_switch_file' => '/etc/watchman/disabled',

            // false for frameworks that already route errors through their own
            // reporter (the Laravel adapter), to avoid double capture.
            'register_handlers' => true,
            // Which PHP errors to capture (still subject to error_reporting()).
            'capture_errors' => E_ALL,

            // Guards: a warning inside a loop must not produce 10 000 events.
            'max_events_per_request' => 50,
            'max_same_error_per_request' => 3,
            'max_breadcrumbs' => 30,
            'max_frames' => 50,
            'max_message_length' => 8192,
            'code_context_lines' => 5,
            'max_source_file_bytes' => 2097152,

            // Probability 0.0-1.0 per event type. Unlisted types are kept.
            'sample_rates' => [
                'PHP_DEPRECATED' => 0.0,
                'PHP_STRICT' => 0.0,
            ],
            // Exception classes (or parents) never reported.
            'ignore_exceptions' => [],

            // 'auto': CLI writes each event immediately (long-running workers),
            // web requests write once at shutdown. 'shutdown' | 'immediate'.
            'flush' => 'auto',

            'send_ip' => true,
            'trust_forwarded_for' => false,
            'request_id_header' => 'X-Request-Id',

            // callable(array $event): ?array  -- return null to drop.
            'before_send' => null,

            // Client-side masking; the server masks again.
            'mask_keys' => [
                'password', 'passwd', 'secret', 'token', 'authorization', 'auth',
                'api_key', 'apikey', 'access_key', 'private_key', 'credentials',
                'card', 'cvv', 'cvc', 'pan', 'ssn', 'session', 'cookie',
            ],
            'mask_patterns' => [
                '/\b(?:\d[ -]*?){13,19}\b/',
                '#<(\w+:)?(Password|Secret|Token)\b[^>]*>.*?</(\w+:)?\2>#is',
                '/\b(Bearer|Basic)\s+[A-Za-z0-9\-._~+\/=]{8,}/i',
                '/\bwm_(sk|pk)_[A-Za-z0-9]{40}\b/',
                // password='...' / password=... inside SQL or query strings
                // \K keeps "password=" and replaces only the value.
                '/\b(?:password|passwd|pwd)\s*=\s*\K(?:\'[^\']*\'|"[^"]*"|[^\s&,;)]+)/i',
            ],
        ];
    }

    /** @return mixed */
    public function get(string $key)
    {
        return $this->values[$key] ?? null;
    }

    public function project(): string
    {
        return $this->values['project'];
    }

    public function isUsable(): bool
    {
        return $this->values['enabled'] && $this->values['project'] !== '';
    }

    private static function slug(string $value): string
    {
        return (string) preg_replace('/[^a-z0-9._-]/', '-', strtolower(trim($value)));
    }
}
