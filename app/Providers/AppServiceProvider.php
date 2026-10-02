<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Spatie\QueryBuilder\QueryBuilderRequest;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register the FlashHelper
        require_once app_path('Helpers/FlashHelper.php');
        $this->app->singleton('flash.helper', function () {
            return new \App\Helpers\FlashHelper;
        });

        // Register WholesaleOut services for editable active WholesaleOuts feature
        $this->app->singleton(\App\Services\WholesaleOutChangeDetector::class);
        $this->app->singleton(\App\Services\ActiveWholesaleOutReconciliationService::class);

        // End to end test helpers, see config/app.php cypress_routes
        if (config('app.cypress_routes')
            && $this->app->environment(['local', 'testing'])
            && class_exists(\Laracasts\Cypress\CypressServiceProvider::class)) {
            $this->app->register(\Laracasts\Cypress\CypressServiceProvider::class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // The Cypress browser runs in its own container and can't reach the Vite dev
        // server on localhost, so the end to end instance always serves the built assets
        if (config('app.cypress_routes')) {
            Vite::useHotFile(storage_path('framework/cypress-no-hot-file'));
        }
        JsonResource::withoutWrapping(); // Don't wrap JsonResource in a "data" key

        // Throttle all queued Discogs listing jobs to stay under the Discogs API rate limit
        // (~60/min authenticated). Kept below that to leave headroom for order sync / updates.
        RateLimiter::for('discogs', fn () => Limit::perMinute(45));

        // Filter values are free-form user input (record titles, customer names) and must
        // never be split into an array: a comma is ordinary content ("Power, Corruption &
        // Lies"), and splitting it hands an array to callbacks that expect a string.
        // `|` cannot be used as the delimiter here, it is already the multi-token separator
        // owned by App\Support\SearchFilter. \x1F (ASCII unit separator) is
        // unreachable from a browser, so this disables spatie's splitting outright and
        // leaves SearchFilter as the only layer that splits a filter value.
        // Filters only: `sorts` stay comma-separated, see RecordController::index().
        QueryBuilderRequest::setFilterArrayValueDelimiter("\x1F");
    }
}
