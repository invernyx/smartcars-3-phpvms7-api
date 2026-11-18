<?php

use App\Contracts\Migration;
use App\Services\Installer\SeederService;
use Illuminate\Support\Facades\DB;

/**
 * Class AddSmartcarsSettings
 */
class AddSmartcarsSettings extends Migration
{
    private const GROUP = 'smartcars';

    private const SETTINGS = [
        'smartcars.airline_code' => [
            'name'        => 'Airline code',
            'value'       => 'iata',
            'options'     => 'iata=IATA code (2 letters),icao=ICAO code (3 letters)',
            'description' => 'The airline code smartCARS shows for bookings and schedules',
        ],
        'smartcars.aircraft_name' => [
            'name'        => 'Aircraft name',
            'value'       => 'detailed',
            'options'     => 'detailed=Name (registration) and status,plain=Name only',
            'description' => 'How smartCARS shows each aircraft of the fleet',
        ],
        'smartcars.cancel_mode' => [
            'name'        => 'Cancelled flights',
            'value'       => 'cancel',
            'options'     => 'cancel=Mark the PIREP cancelled,delete=Delete the unfiled PIREP',
            'description' => 'What happens to the PIREP when a pilot cancels a flight in smartCARS',
        ],
    ];

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $seeder = app(SeederService::class);
        foreach (self::SETTINGS as $key => $attrs) {
            $seeder->addSetting($key, array_merge($attrs, ['group' => self::GROUP, 'type' => 'select']));
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        DB::table('settings')
            ->whereIn('id', array_map(fn ($key) => str_replace('.', '_', $key), array_keys(self::SETTINGS)))
            ->delete();
    }
}
