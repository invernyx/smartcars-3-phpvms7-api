<?php

namespace Modules\SmartCARS3phpVMS7Api\Jobs;

use App\Models\Enums\AcarsType;
use App\Models\Enums\PirepState;
use App\Models\Enums\PirepStatus;
use App\Models\Pirep;
use App\Models\Acars;
use App\Models\PirepComment;
use App\Notifications\Channels\Discord\DiscordMessage;
use GuzzleHttp\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Class ImportOldPireps
 * @package Modules\SmartCARS3phpVMS7Api\Jobs
 */
class ImportOldPireps implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
        if (!Schema::hasTable('smartCARS3_FlightData')) {
            return;
        }
        set_time_limit(0);
        $flightData = DB::table("smartCARS3_FlightData")->orderBy('pirepID')->chunk(1, function($flightData) {
            foreach ($flightData as $data) {
                $pirep = Pirep::find($data->pirepID);
                if ($pirep === null) {
                    continue;
                }
                $pirep->load('comments', 'acars_logs', 'acars');

                $log = json_decode(gzdecode($data->log));
                foreach ($log as $event) {
                    $pirepLog = new PirepComment();
                    $pirepLog->pirep_id = $pirep->id;
                    $pirepLog->user_id = $pirep->user_id;
                    $pirepLog->comment = $event->message;
                    $pirepLog->created_at = $event->eventTimestamp;
                    $pirepLog->save();
                }

                $order = 0;
                $locationData = json_decode(gzdecode($data->locations));
                foreach ($locationData as $location) {
                    $acars = new Acars();
                    $acars->pirep_id = $pirep->id;
                    $acars->type = AcarsType::FLIGHT_PATH;
                    $acars->status = PirepStatus::ENROUTE;
                    $acars->lat= $location->latitude;
                    $acars->lon = $location->longitude;
                    $acars->heading = round($location->heading);
                    $acars->order = $order;
                    $acars->sim_time = str_pad($order++, 10, '0', STR_PAD_LEFT);
                    $acars->created_at = $pirep->created_at;
                    $acars->save();
                }
            }
        });
        Schema::dropIfExists('smartCARS3_FlightData');
    }
}
