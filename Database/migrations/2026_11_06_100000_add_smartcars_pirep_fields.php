<?php

use App\Contracts\Migration;
use App\Models\Enums\PirepFieldSource;
use Illuminate\Support\Facades\DB;

/**
 * Class AddSmartcarsPirepFields
 */
class AddSmartcarsPirepFields extends Migration
{
    private const DESCRIPTION = 'Filled in by smartCARS';

    private const FIELDS = [
        'network'   => 'Network',
        'simulator' => 'Simulator',
    ];

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        foreach (self::FIELDS as $slug => $name) {
            // A VA may already define a field with this name; its definition is kept
            if (DB::table('pirep_fields')->where('slug', $slug)->exists()) {
                continue;
            }
            DB::table('pirep_fields')->insert([
                'name'         => $name,
                'slug'         => $slug,
                'description'  => self::DESCRIPTION,
                'required'     => false,
                'pirep_source' => PirepFieldSource::ACARS,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        DB::table('pirep_fields')
            ->whereIn('slug', array_keys(self::FIELDS))
            ->where('description', self::DESCRIPTION)
            ->delete();
    }
}
