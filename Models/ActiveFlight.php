<?php

namespace Modules\SmartCARS3phpVMS7Api\Models;

use App\Contracts\Model;
use App\Models\Bid;
use App\Models\Pirep;

/**
 * Class ActiveFlight
 * @package Modules\SmartCARS3phpVMS7Api\Models
 */
class ActiveFlight extends Model
{
    public $table = 'sc_active_flights';
    public $fillable = ['bid_id', 'pirep_id'];
    public function bid() {
        return $this->belongsTo(Bid::class);
    }
    public function pirep() {
        return $this->belongsTo(Pirep::class);
    }
    public $timestamps = false;
}
