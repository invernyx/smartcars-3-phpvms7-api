<?php

namespace Modules\SmartCARS3phpVMS7Api\Jobs;

use App\Models\Pirep;
use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Modules\SmartCARS3phpVMS7Api\Actions\PirepDistanceCalculation;

/**
 * Class CalculatePirepDistance
 * @package Modules\SmartCARS3phpVMS7Api\Jobs
 */
class CalculatePirepDistance implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     *
     * @return void
     */
    public function __construct(public Pirep $pirep)
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
        //
        $distance = PirepDistanceCalculation::calculatePirepDistance($this->pirep);
        $this->pirep->distance = $distance;
        $this->pirep->save();
    }
}
