<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Cheap detector for "are there migrations that haven't run yet?".
 *
 * This exists so the admin surface can decide, on every request, whether to
 * route a Super_Admin to the build page (see
 * RedirectToBuildPageWhenMigrationsPending) WITHOUT paying the cost of
 * `php artisan migrate:status` on each page load. That artisan command
 * bootstraps the migrator and resolves every migration class; here we just
 * compare the migration FILENAMES on disk against the `migration` column of the
 * `migrations` table — one directory read + one indexed query.
 *
 * The result is cached briefly so a burst of admin requests doesn't re-run the
 * check each time. The window is short (seconds) so that immediately after you
 * click "Run migrations" the banner/redirect clears on the next navigation.
 *
 * Fail-safe: if anything goes wrong (no DB connection, missing `migrations`
 * table on a brand-new install, unreadable path) we return an EMPTY pending
 * list rather than throwing. A detector that can't answer must never take down
 * the admin area or trap the operator in a redirect loop — the explicit build
 * page still shows the real `migrate:status`, so nothing is hidden.
 */
class PendingMigrations
{
    /**
     * Seconds to cache the "pending" answer. Short by design: long enough to
     * absorb a page's worth of requests, short enough that the banner clears
     * promptly after migrations run.
     */
    private const CACHE_TTL = 15;

    private const CACHE_KEY = 'ops.pending_migrations';

    /**
     * The migration names present on disk but absent from the `migrations`
     * table, i.e. the ones a `migrate` run would apply. Empty when the schema
     * is up to date (or when the check can't run — see fail-safe above).
     *
     * @return list<string>
     */
    public function pending(bool $useCache = true): array
    {
        if (! $useCache) {
            return $this->compute();
        }

        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, fn () => $this->compute());
    }

    /**
     * True when at least one migration on disk has not been recorded as run.
     */
    public function hasPending(bool $useCache = true): bool
    {
        return $this->pending($useCache) !== [];
    }

    /**
     * Drop the cached answer. Call this right after running migrations so the
     * next request re-evaluates against the now-updated `migrations` table
     * instead of serving a stale "pending" verdict.
     */
    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * The actual comparison: on-disk migration files minus already-run ones.
     *
     * @return list<string>
     */
    private function compute(): array
    {
        try {
            // No migrations table yet (fresh DB) => everything on disk is pending,
            // but we still want the build page rather than a crash. Treat the
            // table's absence as "can't determine cheaply"; the build page's own
            // migrate:status will show the true state. Returning the on-disk set
            // here would correctly flag pending, so do that.
            $onDisk = $this->migrationsOnDisk();

            if (! Schema::hasTable('migrations')) {
                return $onDisk;
            }

            $ran = DB::table('migrations')->pluck('migration')->all();
            $ranLookup = array_flip($ran);

            return array_values(array_filter(
                $onDisk,
                static fn (string $name) => ! isset($ranLookup[$name]),
            ));
        } catch (\Throwable) {
            // Fail safe: never let the detector break the admin area.
            return [];
        }
    }

    /**
     * Migration "names" as Laravel records them: the filename without the
     * trailing `.php`. Mirrors the framework's own convention so the strings
     * match the `migrations.migration` column exactly.
     *
     * @return list<string>
     */
    private function migrationsOnDisk(): array
    {
        $path = database_path('migrations');

        if (! File::isDirectory($path)) {
            return [];
        }

        return collect(File::files($path))
            ->map(fn ($file) => $file->getFilenameWithoutExtension())
            ->filter(fn (string $name) => $name !== '')
            ->values()
            ->all();
    }
}
