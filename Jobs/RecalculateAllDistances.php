<?php

namespace Modules\SmartCARS3phpVMS7Api\Jobs;

use App\Models\Enums\PirepState;
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
        foreach ($null_distance_pireps as $p) {
            $p->update(['distance' => PirepDistanceCalculation::calculatePirepDistance($p)]);
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
