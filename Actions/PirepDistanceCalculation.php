<?php

namespace Modules\SmartCARS3phpVMS7Api\Actions;

use App\Models\Pirep;
use App\Services\GeoService;
use League\Geotools\Coordinate\Coordinate;
use League\Geotools\Geotools;

class PirepDistanceCalculation
{
    public static function calculatePirepDistance(Pirep $pirep) : float
    {
        //
        $path_points = $pirep->acars()->get();

        $distance = 0;

        for($i = 0; $path_points->count() < $i - 1; $i++) {
            $from = $path_points[$i];
            $to = $path_points[$i + 1];

            $geotools = new Geotools();
            $start = new Coordinate([$from->lat, $from->lon]);
            $end = new Coordinate([$to->lat, $to->lon]);
            $dist = $geotools->distance()->setFrom($start)->setTo($end);

            $distance = $distance + $dist->in(config('phpvms.internal_units.distance', 'nmi'))->greatCircle();
        }
        return $distance;
    }
}
