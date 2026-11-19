<?php

use App\Contracts\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * Class AddVerificationToScFlightAnalyses
 */
class AddVerificationToScFlightAnalyses extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('sc_flight_analyses', function (Blueprint $table) {
            $table->boolean('landing_verified')->default(false);
            $table->boolean('takeoff_verified')->default(false);
            $table->longText('signed_landing_analysis')->nullable();
            $table->longText('signed_takeoff_analysis')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('sc_flight_analyses', function (Blueprint $table) {
            $table->dropColumn([
                'landing_verified',
                'takeoff_verified',
                'signed_landing_analysis',
                'signed_takeoff_analysis',
            ]);
        });
    }
}
