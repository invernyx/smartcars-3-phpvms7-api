<?php

namespace Modules\SmartCARS3phpVMS7Api\Listeners;

use App\Contracts\Listener;
use App\Models\Bid;
use App\Models\Enums\PirepState;
use App\Models\Flight;
use App\Models\Pirep;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Modules\SmartCARS3phpVMS7Api\Providers\AppServiceProvider;

/**
 * Class DeleteCharterFlights
 * @package Modules\SmartCARS3phpVMS7Api\Listeners
 */
class DeleteCharterFlights extends Listener
{
    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     *
     * @param  object  $event
     * @return void
     */
    public function handle($event)
    {
        // Find all the flights
        $flights = Flight::where('owner_type', AppServiceProvider::class)->get();

        // We're going to only delete flights that don't have a bid, or a pirep that's completed.

        foreach ($flights as $flight) {

            // if Pirep is in progress, then don't do anything.
            $pirep = Pirep::where(['flight_id' => $flight->id, 'user_id' => $flight->user_id])->first();
            if ($pirep->state == PirepState::IN_PROGRESS) {
                continue;
            }

            // Check if there's a bid.
            $bids = Bid::where('flight_id', $flight->id)->count();
            if ($bids == 0) {
                $flight->delete();
                continue;
            }
            $flight->visible = false;
            $flight->save();
        }
    }
}
