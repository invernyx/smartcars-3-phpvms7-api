<?php

namespace Modules\SmartCARS3phpVMS7Api\Providers;

use App\Events\CronNightly;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\SmartCARS3phpVMS7Api\Listeners\DeleteCharterFlights;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     */
    protected $listen = [
        CronNightly::class => [DeleteCharterFlights::class]
    ];

    /**
     * Register any events for your application.
     */
    public function boot()
    {
        parent::boot();
    }
}
