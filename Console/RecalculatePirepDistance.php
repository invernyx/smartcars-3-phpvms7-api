<?php

namespace Modules\SmartCARS3phpVMS7Api\Console;

use App\Models\Enums\PirepState;
use App\Models\Pirep;
use Illuminate\Console\Command;
use Modules\SmartCARS3phpVMS7Api\Actions\PirepDistanceCalculation;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;

class RecalculatePirepDistance extends Command
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $name = 'smartcars3:recalculatepirepdistance';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recalculates PIREP distances based on available telemetry for all smartCARS 3 sourced pireps.';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $pireps = Pirep::where(['state' => PirepState::ACCEPTED, 'source_name' => 'smartCARS 3'])->get();
        foreach ($pireps as $pirep) {
            $distance = PirepDistanceCalculation::calculatePirepDistance($pirep);
            $pirep->update(['distance' => $distance]);
        }
        $this->info("Recalculated ".$pireps->count()." Reports");
    }
}
