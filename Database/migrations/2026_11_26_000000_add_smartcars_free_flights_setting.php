<?php

use App\Contracts\Migration;
use App\Models\Setting;
use App\Services\Installer\SeederService;
use Illuminate\Support\Facades\DB;

/**
 * Class AddSmartcarsFreeFlightsSetting
 */
class AddSmartcarsFreeFlightsSetting extends Migration
{
    private const KEY = 'smartcars.free_flights';

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        app(SeederService::class)->addSetting(self::KEY, [
            'name'        => 'Free flights',
            'group'       => 'smartcars',
            'value'       => 'false',
            'type'        => 'boolean',
            'description' => 'Let smartCARS pilots fly free flights: flights with no schedule or bid, filed as PIREPs for review.',
        ]);
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        DB::table('settings')->where('id', Setting::formatKey(self::KEY))->delete();
    }
}
