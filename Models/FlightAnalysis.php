<?php

namespace Modules\SmartCARS3phpVMS7Api\Models;

use App\Contracts\Model;

/**
 * Class FlightAnalysis
 * @package Modules\SmartCARS3phpVMS7Api\Models
 */
class FlightAnalysis extends Model
{
    public $table = 'sc_flight_analyses';
    protected $fillable = [
        'pirep_id',
        'user_id',
        'format_version',
        'touchdown_rate_fpm',
        'landing_grade',
        'flight_score',
        'landing_analysis',
        'flight_score_detail',
        'takeoff_analysis',
        'debrief',
        'approach_trail',
        'takeoff_trail',
        'landing_verified',
        'takeoff_verified',
        'signed_landing_analysis',
        'signed_takeoff_analysis',
    ];

    protected $casts = [
        'format_version'   => 'integer',
        'landing_verified' => 'boolean',
        'takeoff_verified' => 'boolean',
    ];

    public static $rules = [

    ];
}
