<?php

use App\Contracts\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * Class CreateScFlightAnalysesTable
 */
class CreateScFlightAnalysesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('sc_flight_analyses', function (Blueprint $table) {
            $table->increments('id');
            $table->string('pirep_id', 36)->unique();
            $table->unsignedInteger('user_id')->index();
            $table->unsignedSmallInteger('format_version');
            $table->decimal('touchdown_rate_fpm', 8, 2)->nullable();
            $table->string('landing_grade', 32)->nullable();
            $table->decimal('flight_score', 5, 2)->nullable();
            $table->timestamps();
            $table->longText('landing_analysis')->nullable();
            $table->longText('flight_score_detail')->nullable();
            $table->longText('takeoff_analysis')->nullable();
            $table->longText('debrief')->nullable();
            $table->longText('approach_trail')->nullable();
            $table->longText('takeoff_trail')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('sc_flight_analyses');
    }
}
