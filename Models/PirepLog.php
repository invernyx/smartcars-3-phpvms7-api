<?php

namespace Modules\SmartCARS3phpVMS7Api\Models;

use App\Contracts\Model;

/**
 * Class PirepLog
 * @package Modules\SmartCARS3phpVMS7Api\Models
 */
class PirepLog extends Model
{
    public $table = 'sc_pirep_logs';
    protected $fillable = ['pirep_id', 'log'];

    protected $casts = [

    ];

    public static $rules = [

    ];
}
