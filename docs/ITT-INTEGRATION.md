# ITT integration plan (Phase 3)

Goal: real ITT staging errors on the Watchman dashboard, with no measurable
slowdown and a one-command rollback.

## 1. Before touching code: DB layer audit

The value of `Watchman::sql()` depends on how many places run queries.

```bash
cd /var/www/itt
grep -rn --include='*.php' -E 'mysqli_query|->query\(|->prepare\(|->exec\(' . | grep -v /vendor/ | wc -l
grep -rln --include='*.php' -E 'mysqli_query|->query\(' . | grep -v /vendor/ | sort | uniq -c | sort -rn | head -30
```

- One DB wrapper class: add `Watchman::sql()` there (about 1 day).
- Queries scattered: wrap only the wrapper + the 10 hottest files now, rest later.

## 2. Install

```bash
composer config repositories.watchman vcs git@github.com:<org>/watchman-php.git
composer require itt/watchman-php:^0.1
```

## 3. Bootstrap (one include, first thing after the autoloader)

```php
// config/watchman.php, required from the common bootstrap/config include
Watchman\Watchman::init([
    'project'     => 'itt-ota',
    'app_root'    => '/var/www/itt',
    'environment' => ITT_ENV,                       // staging / production
    'release'     => getenv('WATCHMAN_RELEASE') ?: null,
    // PHP 8 migration: see deprecations on staging only
    'sample_rates' => ITT_ENV === 'staging' ? ['PHP_DEPRECATED' => 1.0] : ['PHP_DEPRECATED' => 0.0],
]);
```

After login, wherever the session user and agency are known:

```php
Watchman\Watchman::setUser($_SESSION['user_id'] ?? null, $_SESSION['agency_id'] ?? null);
```

## 4. DB wrapper

```php
$start = microtime(true);
$result = mysqli_query($this->link, $sql);
Watchman\Watchman::sql($sql, (microtime(true) - $start) * 1000, $result === false ? mysqli_error($this->link) : null);
```

## 5. Supplier catch blocks

```php
} catch (Exception $e) {
    Watchman\Watchman::supplier('Travelport', 'AirSearch', $e, ['duration_ms' => $ms, 'search_id' => $searchId]);
    // existing handling unchanged
}
```

Start with the top suppliers by traffic (Travelport, Sabre, Emirates, Turkish).

## 6. Release tag

Deploy pipeline exports the tag so events carry it:

```bash
echo "WATCHMAN_RELEASE=$(git describe --tags --always)" | sudo tee /etc/watchman/release.env
```

(PHP-FPM pool: `env[WATCHMAN_RELEASE] = v1.6.2`, or read the file in the bootstrap.)

## 7. Server setup (per VPS)

See README "Shipper". Server name in `/etc/watchman/ship.php` must be unique.

## 8. Gate

- 3 days on staging, no errors or slowdown caused by the SDK
- Benchmark: p95 difference < 2 ms between SDK on and off, on the staging server itself:

  ```bash
  # urls.txt: search, fare quote, booking steps, and one page that raises a warning
  sudo vendor/itt/watchman-php/tools/bench-overhead.sh -u urls.txt -n 200 -H "Cookie: PHPSESSID=<staging session>"
  ```

  It toggles `/etc/watchman/disabled` round by round (so drift hits both modes),
  prints p50/p95/mean per mode and PASS/FAIL, and restores the kill switch state
  on exit. On the demo app: p95 diff −0.07 ms (noise).
- Rollback drill: `touch /etc/watchman/disabled`, confirm no new spool files
