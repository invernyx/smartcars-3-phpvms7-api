<?php

namespace Modules\SmartCARS3phpVMS7Api\Providers;

use App\Contracts\Modules\ServiceProvider;
use Modules\SmartCARS3phpVMS7Api\Console\RecalculatePirepDistance;

/**
 * @package $NAMESPACE$
 */
class AppServiceProvider extends ServiceProvider
{
    private $moduleSvc;

    protected $defer = false;

    /**
     * Boot the application events.
     */
    public function boot(): void
    {
        $this->moduleSvc = app('App\Services\ModuleService');

        $this->registerTranslations();
        $this->registerConfig();
        //$this->registerViews();

        // Uncomment this if you have migrations
        $this->loadMigrationsFrom(__DIR__ . '/../Database/migrations');

    }

    /**
     * Register the service provider.
     */
    public function register()
    {
        //
    }

    /**
     * Add module links here
     */
    public function registerLinks(): void
    {

    }

    /**
     * Register config.
     */
    protected function registerConfig()
    {
        $this->publishes([
            __DIR__.'/../Config/config.php' => config_path('smartcars3phpvms7api.php'),
        ], 'smartcars3phpvms7api');

        $this->mergeConfigFrom(__DIR__.'/../Config/config.php', 'smartcars3phpvms7api');
    }

    /**
     * Register views.
     */
//    public function registerViews()
//    {
//        $viewPath = resource_path('views/modules/smartcars3phpvms7api');
//        $sourcePath = __DIR__.'/../Resources/views';
//
//        $this->publishes([$sourcePath => $viewPath],'views');
//
//        $this->loadViewsFrom(array_merge(array_map(function ($path) {
//            return $path . '/modules/smartcars3phpvms7api';
//        }, \Config::get('view.paths')), [$sourcePath]), 'smartcars3phpvms7api');
//    }

    /**
     * Register translations.
     */
    public function registerTranslations()
    {
        $langPath = resource_path('lang/modules/smartcars3phpvms7api');

        if (is_dir($langPath)) {
            $this->loadTranslationsFrom($langPath, 'smartcars3phpvms7api');
        } else {
            $this->loadTranslationsFrom(__DIR__ .'/../Resources/lang', 'smartcars3phpvms7api');
        }
    }
}
