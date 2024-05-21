<?php

namespace Modules\SmartCARS3phpVMS7Api\Jobs;

use App\Models\Enums\PirepState;
use App\Models\Enums\PirepStatus;
use App\Models\Pirep;
use App\Notifications\Channels\Discord\DiscordMessage;
use GuzzleHttp\Client;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Modules\SmartCARS3phpVMS7Api\Actions\PirepDistanceCalculation;

/**
 * Class RecalculateAllDistances
 * @package Modules\SmartCARS3phpVMS7Api\Jobs
 */
class RecalculateAllDistances implements ShouldQueue
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
        $client = new Client();
        if (setting('notifications.discord_private_webhook_url') !== "") {
            $client->request('POST', setting('notifications.discord_private_webhook_url'), [
                'form_params' => [
                    'content' => "smartCARS PIREP Recalculation Started"
                ]
            ]);
        }

        $null_distance_pireps = Pirep::where(['source_name' => "smartCARS 3"])->get();

        Log::debug("Detected ".$null_distance_pireps->count()." Null Distance Pireps");

        if (setting('notifications.discord_private_webhook_url') !== "") {
            $client->request('POST', setting('notifications.discord_private_webhook_url'), [
                'form_params' => [
                    'content' => "Recalculating: ".$null_distance_pireps->count()." PIREPs"
                ]
            ]);
        }
        $progress = 0;
        foreach ($null_distance_pireps as $p) {
            if (is_int($progress/500)) {
                if (setting('notifications.discord_private_webhook_url') !== "") {
                    $client->request('POST', setting('notifications.discord_private_webhook_url'), [
                        'form_params' => [
                            'content' => "Currently at ".$progress
                        ]
                    ]);
                }
            }
            $p->update([
                'distance'       => PirepDistanceCalculation::calculatePirepDistance($p),
                //'block_off_time' => optional($p->acars()->where('status', PirepStatus::INIT_CLIM)->first())->created_at,
                //'block_on_time'  => optional($p->acars()->where('status', PirepStatus::LANDED)->first())->created_at
                ]);
            $progress++;
        }
        if (setting('notifications.discord_private_webhook_url') !== "") {
            $client->request('POST', setting('notifications.discord_private_webhook_url'), [
                'form_params' => [
                    'content' => "smartCARS PIREP Recalculation Complete. {$null_distance_pireps->count()} PIREPs successfully recalculated."
                ]
            ]);
        }
    }
}
