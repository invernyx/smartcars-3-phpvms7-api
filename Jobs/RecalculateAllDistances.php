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

    private const CHUNK_SIZE = 100;

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
        $webhookUrl = setting('notifications.discord_private_webhook_url');
        $this->notifyDiscord($client, $webhookUrl, "smartCARS PIREP Recalculation Started");

        $null_distance_pireps = Pirep::where('source_name', 'smartCARS 3')
            ->where(fn ($q) => $q->whereNull('distance')->orWhere('distance', 0));
        $null_distance_count = $null_distance_pireps->count();

        Log::debug("Detected ".$null_distance_count." Null Distance Pireps");

        $this->notifyDiscord($client, $webhookUrl, "Recalculating: ".$null_distance_count." PIREPs");
        $progress = 0;
        $failed = 0;
        $null_distance_pireps->chunkById(self::CHUNK_SIZE, function ($pireps) use ($client, $webhookUrl, &$progress, &$failed) {
            foreach ($pireps as $p) {
                if (is_int($progress/500)) {
                    $this->notifyDiscord($client, $webhookUrl, "Currently at ".$progress);
                }
                try {
                    $p->update([
                        'distance'       => PirepDistanceCalculation::calculatePirepDistance($p),
                        //'block_off_time' => optional($p->acars()->where('status', PirepStatus::INIT_CLIM)->first())->created_at,
                        //'block_on_time'  => optional($p->acars()->where('status', PirepStatus::LANDED)->first())->created_at
                        ]);
                } catch (\Throwable $e) {
                    Log::warning('smartCARS: could not recalculate the distance of PIREP '.$p->id.': '.$e->getMessage());
                    $failed++;
                }
                $progress++;
            }
        });
        $recalculated = $progress - $failed;
        $this->notifyDiscord($client, $webhookUrl, "smartCARS PIREP Recalculation Complete. {$recalculated} PIREPs successfully recalculated.");
    }

    private function notifyDiscord(Client $client, $webhookUrl, string $content): void
    {
        if (empty($webhookUrl)) {
            return;
        }
        try {
            $client->request('POST', $webhookUrl, [
                'form_params' => [
                    'content' => $content
                ]
            ]);
        } catch (\Throwable $e) {
            Log::warning('smartCARS: could not post the recalculation progress to Discord: '.$e->getMessage());
        }
    }
}
