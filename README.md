# watchman-php

PHP SDK for Watchman. PHP 7.4+, no dependencies.

The SDK never talks to the network. It writes events to a local spool folder,
and `bin/ship.php` (cron, every minute) sends them to the Watchman server. If the
server is slow or down, the application does not notice.

```
app (PHP-FPM) ──► /var/spool/watchman/{project}/*.jsonl ──► ship.php (cron) ──► POST /api/v1/errors/batch
```

## Install

Requirements: PHP 7.4+ (tested on 7.4, 8.1, 8.3), `ext-json`; `ext-curl` for the shipper (falls back
to streams). No other dependencies.

```bash
# private repo, no Packagist needed
composer config repositories.watchman vcs git@github.com:<org>/watchman-php.git
composer require itt/watchman-php:^0.1
```

Without Composer (legacy app): copy the repo to e.g. `/opt/watchman-php` and
register a small autoloader before `init()`:

```php
spl_autoload_register(function ($class) {
    if (strpos($class, 'Watchman\\') === 0) {
        $file = '/opt/watchman-php/src/'.str_replace('\\', '/', substr($class, 9)).'.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
```

Then, per application server:

1. `Watchman::init()` in the bootstrap (below).
2. `/etc/watchman/ship.php` with the endpoint and the project's **secret** key (see [Shipper](#shipper)).
3. The cron line for `bin/ship.php`.
4. Check: trigger an error, wait a minute, it appears in Watchman. `php bin/ship.php --config=/etc/watchman/ship.php --verbose` shows what it sent.

Step-by-step for ITT: [docs/ITT-INTEGRATION.md](docs/ITT-INTEGRATION.md).

## Plain PHP (ITT)

```php
require '/var/www/itt/vendor/autoload.php';

Watchman\Watchman::init([
    'project'  => 'itt-ota',                 // spool folder; ship.php maps it to the API key
    'app_root' => '/var/www/itt',            // paths are stored relative to this
    'release'  => getenv('WATCHMAN_RELEASE') ?: null,
    'environment' => 'production',
]);

Watchman\Watchman::setUser($userId, $agencyId);  // after login
```

That registers error, exception and shutdown handlers. PHP keeps behaving
exactly as before: warnings are still logged, uncaught exceptions still print
"Uncaught ..." and exit 255, previous handlers still run.

Helpers:

```php
Watchman::supplier('Travelport', 'AirSearch', $e, ['duration_ms' => 30012]);  // SUPPLIER_ERROR
Watchman::sql($query, $ms);                    // breadcrumb
Watchman::sql($query, $ms, mysqli_error($db)); // breadcrumb + DB_ERROR
Watchman::captureException($e);
Watchman::captureMessage('Fare mismatch', 'warning', ['pnr' => $pnr]);
Watchman::setTag('flow', 'booking');
Watchman::addBreadcrumb('payment', 'redirect to gateway', ['gateway' => 'stripe']);
```

Every call is a silent no-op if `init()` was not called or the kill switch is on.

## Laravel

Auto-discovered. In `.env`:

```
WATCHMAN_PROJECT=demo-laravel
WATCHMAN_RELEASE=v2.0.0
```

The adapter hooks Laravel's reporting (`reportable`), so whatever Laravel would
log is also recorded, and Laravel's `dontReport` list (404, validation, auth)
applies. It does not install its own PHP handlers, so nothing is reported twice.
SQL queries become breadcrumbs (text only, never bindings); queue jobs get a
clean scope each. Optional: `php artisan vendor:publish --tag=watchman-config`.

## Shipper

```bash
sudo mkdir -p /etc/watchman /var/spool/watchman
sudo cp vendor/itt/watchman-php/config/ship.example.php /etc/watchman/ship.php   # add keys
sudo chown root:www-data /etc/watchman/ship.php && sudo chmod 0640 /etc/watchman/ship.php
sudo chown www-data:www-data /var/spool/watchman && sudo chmod 0770 /var/spool/watchman
```

Cron as the same user as PHP-FPM:

```
* * * * * php /var/www/itt/vendor/bin/ship.php --config=/etc/watchman/ship.php >> /var/log/watchman-ship.log 2>&1
```

| Server answer | Shipper |
|---|---|
| 2xx | deletes the files |
| 400 / 401 / 403 / 404 / 413 | moves them to `{project}/failed/` (kept 7 days) and logs |
| 429 / 5xx / network error | keeps them, retries next minute |

One run at a time (`flock`), max 50 s, batches of ≤100 events / 200 KB. If the
spool grows past `max_spool_mb` (default 512), the oldest files are dropped.

## Kill switch

```bash
sudo touch /etc/watchman/disabled     # SDK: no handlers, no I/O. Shipper: stops.
sudo rm /etc/watchman/disabled        # back on (next request)
```

## Guards

| Guard | Default |
|---|---|
| Events per request | 50 |
| Same error (type+file+line) per request | 3 |
| Breadcrumbs kept | 30 |
| Stack frames | 50, no arguments ever |
| Source context | ±5 lines, files under 2 MB |
| Message length | 8 KB |
| `PHP_DEPRECATED`, `PHP_STRICT` | sampled at 0 |
| Masking | password/token/card/... keys; card numbers, SOAP `<Password>`, Bearer, `password=` in SQL |

All options: `src/Config.php`.

## Overhead benchmark

```bash
sudo tools/bench-overhead.sh -u urls.txt -n 200 [-H 'Cookie: ...']
```

Runs on the app server, alternates SDK off/on (kill switch) per round, prints
p50/p95 per mode and PASS when the p95 difference is under 2 ms.

## Development

```bash
make install   # composer in Docker
make test      # PHPUnit on PHP 7.4, 8.1, 8.3
make guard     # fails on PHP 8-only syntax
```
