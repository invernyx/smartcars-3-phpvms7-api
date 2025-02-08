<?php

namespace Modules\SmartCARS3phpVMS7Api\Listeners;

use App\Contracts\Listener;
use App\Models\Enums\PirepState;
use App\Models\Pirep;
use Exception;
use Illuminate\Support\Facades\Log;
use Modules\SmartCARS3phpVMS7Api\Models\ActiveFlight;

/**
 * Class DeleteCharterFlights
 * @package Modules\SmartCARS3phpVMS7Api\Listeners
 */
class CleanActiveFlights extends Listener
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
        $active_flights = ActiveFlight::all();
        try {
            foreach ($active_flights as $active_flight) {
                $pirep = Pirep::find($active_flight->pirep_id);
                if ($pirep && $pirep->state !== PirepState::IN_PROGRESS) {
                    $active_flight->delete();
                }
            }
        } catch (Exception $e) {
            Log::error($e);
        }
    }
}
