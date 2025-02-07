<?php

namespace Modules\SmartCARS3phpVMS7Api\Providers;

use App\Events\CronFifteenMinute;
use App\Events\CronFiveMinute;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\SmartCARS3phpVMS7Api\Listeners\CleanActiveFlights;
use Modules\SmartCARS3phpVMS7Api\Listeners\DeleteCharterFlights;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     */
    protected $listen = [
        CronFifteenMinute::class => [DeleteCharterFlights::class],
        CronFiveMinute::class    => [CleanActiveFlights::class]
    ];

    /**
     * Register any events for your application.
     */
    public function boot()
    {
        parent::boot();
    }
}
