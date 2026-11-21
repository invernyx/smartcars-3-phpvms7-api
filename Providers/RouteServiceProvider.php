<?php

namespace Modules\SmartCARS3phpVMS7Api\Providers;

use Illuminate\Routing\Router;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Route;
use Modules\SmartCARS3phpVMS7Api\Jobs\RecalculateAllDistances;
use Modules\SmartCARS3phpVMS7Api\Jobs\ImportOldPireps;

/**
 * Register the routes required for your module here
 */
class RouteServiceProvider extends ServiceProvider
{
    /**
     * The root namespace to assume when generating URLs to actions.
     *
     * @var string
     */
    protected $namespace = 'Modules\SmartCARS3phpVMS7Api\Http\Controllers';

    /**
     * Called before routes are registered.
     *
     * Register any model bindings or pattern based filters.
     *
     * @param  Router $router
     * @return void
     */
    public function before(Router $router)
    {
        //
    }

    /**
     * Define the routes for the application.
     *
     * @param \Illuminate\Routing\Router $router
     *
     * @return void
     */
    public function map(Router $router)
    {
        $this->registerApiRoutes();
        $this->registerAdminRoutes();
    }
    public function registerAdminRoutes(): void
    {
        $config = [
            'as'         => 'admin.smartcars3phpvms7api.',
            'prefix'     => 'admin/smartcars',
            'namespace'  => $this->namespace.'\Admin',
            'middleware' => ['web', 'role:admin'],
        ];

        Route::group($config, function() {
            Route::get('import', function() {
                return self::confirmationPage(
                    'Import old smartCARS 3 flight data',
                    'This queues a job that copies the flight logs and flight paths saved by the old smartCARS 3 module into their PIREPs.',
                    url('admin/smartcars/import')
                );
            });
            Route::post('import', function() {
                ImportOldPireps::dispatch();
                return "Old Pirep Import Job Queued. Please wait for pireps to get imported.";
            });
            Route::get('recalc', function() {
                return self::confirmationPage(
                    'Recalculate PIREP distances',
                    'This queues a job that calculates the distance of every smartCARS 3 PIREP that has none.',
                    url('admin/smartcars/recalc')
                );
            });
            Route::post('recalc', function() {
                RecalculateAllDistances::dispatch();
                return "Pirep Calculation Job Queued. Please wait up to 10 minutes for pireps to get recalculated. If you have your private discord notification channel setup properly, you will receive notifications when this has been completed.";
            });
        });
    }

    // Static so the route closures never use $this, which route:cache would then have to serialize.
    private static function confirmationPage(string $title, string $description, string $action): string
    {
        return '<!DOCTYPE html><html><head><meta charset="utf-8"><title>'.e($title).'</title></head><body>'
            .'<h1>'.e($title).'</h1>'
            .'<p>'.e($description).'</p>'
            .'<form method="POST" action="'.e($action).'">'.csrf_field()
            .'<button type="submit">Queue the job</button></form>'
            .'</body></html>';
    }

    /**
     * Register any API routes your module has. Remove this if you aren't using any
     */
    protected function registerApiRoutes(): void
    {
        $config = [
            'as'         => 'api.smartcars3phpvms7api.',
            'prefix'     => 'api/smartcars',
            'namespace'  => $this->namespace.'\Api',
            'middleware' => ['api'],
        ];

        Route::group($config, function() {
            $this->loadRoutesFrom(__DIR__.'/../Http/Routes/api.php');
        });
    }
}
