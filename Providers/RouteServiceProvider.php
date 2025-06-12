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
                ImportOldPireps::dispatch();
                return "Old Pirep Import Job Queued. Please wait for pireps to get imported.";
            });
            Route::get('recalc', function() {
                RecalculateAllDistances::dispatch();
                return "Pirep Calculation Job Queued. Please wait up to 10 minutes for pireps to get recalculated. If you have your private discord notification channel setup properly, you will receive notifications when this has been completed.";
            });
        });
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
