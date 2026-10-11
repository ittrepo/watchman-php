<?php

namespace Watchman\Laravel;

use Watchman\Watchman;
use Illuminate\Auth\Events\Authenticated;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\ServiceProvider;

/**
 * Auto-discovered. Set WATCHMAN_PROJECT in .env and run ship.php from cron.
 *
 * Laravel already owns the PHP error/exception handlers and turns warnings into
 * ErrorException, so the SDK does not register its own (that would report every
 * warning twice). It hooks Laravel's reporting pipeline instead: whatever
 * Laravel would log, Watchman also records, and Laravel's dontReport list applies.
 */
class WatchmanServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/watchman-laravel.php', 'watchman');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/watchman-laravel.php' => $this->app->configPath('watchman.php'),
            ], 'watchman-config');
        }

        $config = (array) $this->app['config']->get('watchman', []);

        if (empty($config['project']) || empty($config['enabled'])) {
            return;
        }

        $config['register_handlers'] = false;
        $config['app_root'] = $config['app_root'] ?? $this->app->basePath();
        $config['environment'] = $config['environment'] ?? $this->app->environment();

        if (Watchman::init($config) === null) {
            return; // kill switch or invalid config
        }

        $this->hookReporting();
        $this->hookEvents(! empty($config['sql_breadcrumbs']));
    }

    /** @var array<int, true> Handlers already hooked (resolving callbacks can fire more than once). */
    private static $hooked = [];

    private function hookReporting(): void
    {
        $this->callAfterResolving(ExceptionHandler::class, function ($handler) {
            $id = spl_object_id($handler);
            if (isset(self::$hooked[$id])) {
                return;
            }
            self::$hooked[$id] = true;

            if (method_exists($handler, 'reportable')) {
                // Returning nothing keeps Laravel's own logging unchanged.
                $handler->reportable(function (\Throwable $e) {
                    Watchman::captureException($e);
                });
            }
        });
    }

    private function hookEvents(bool $sql): void
    {
        $events = $this->app['events'];

        if ($sql) {
            $events->listen(QueryExecuted::class, function (QueryExecuted $query) {
                Watchman::sql($query->sql, (float) $query->time);
            });
        }

        $events->listen(Authenticated::class, function (Authenticated $event) {
            Watchman::setUser($event->user->getAuthIdentifier());
        });

        // Queue workers are one long process: give every job a clean scope and
        // write its events as soon as it finishes.
        $events->listen(JobProcessing::class, function (JobProcessing $event) {
            Watchman::resetRequest();
            Watchman::setTag('job', $event->job->resolveName());
            Watchman::setTag('queue', $event->job->getQueue());
        });
        $events->listen(JobProcessed::class, function () {
            Watchman::flush();
        });
        $events->listen(JobFailed::class, function () {
            Watchman::flush();
        });
    }
}
