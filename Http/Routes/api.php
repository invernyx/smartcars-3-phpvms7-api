<?php
use \Modules\SmartCARS3phpVMS7Api\Http\Controllers\Api\PilotController;
use \Modules\SmartCARS3phpVMS7Api\Http\Controllers\Api\DataController;
use \Modules\SmartCARS3phpVMS7Api\Http\Controllers\Api\PirepsController;
use \Modules\SmartCARS3phpVMS7Api\Http\Controllers\Api\FlightsController;
use \Modules\SmartCARS3phpVMS7Api\Http\Controllers\Api\AnalysesController;
use \Modules\SmartCARS3phpVMS7Api\Http\Controllers\Api\AutoUpdateController;
use \Modules\SmartCARS3phpVMS7Api\Http\Middleware\SCAuth;
use \Modules\SmartCARS3phpVMS7Api\Http\Middleware\SCHeaders;

/**
 * This is publicly accessible
 */

Route::group(['middleware' => [SCHeaders::class]], function() {
    Route::match(['get', 'options'], '/', function () {
        $moduleJson = \Illuminate\Support\Facades\File::get(base_path('modules/SmartCARS3phpVMS7Api/module.json'));
        $moduleData = json_decode($moduleJson, true);
        $version = $moduleData['version'] ?? 'unknown';
        $commit = (string) (include base_path('modules/SmartCARS3phpVMS7Api/Support/InstalledCommit.php'));
        return response()->json(["apiVersion" => $version, "handler" => "phpvms7", "commit" => $commit, "capabilities" => \Modules\SmartCARS3phpVMS7Api\Support\Capabilities::all()]);
    })->defaults('scCapability', ['flight-phases', 'iata-flight-types']);
    Route::match(['post', 'options'], '/pilot/login', [PilotController::class, 'login']);
    Route::match(['post', 'options'], '/pilot/resume', [PilotController::class, 'resume']);
    Route::match(['post', 'options'], '/pilot/verify', [PilotController::class, 'verify']);
    Route::match(['post', 'options'], '/auto-update', [AutoUpdateController::class, 'receive'])->defaults('scCapability', 'auto-update')->defaults('scCapabilitySetting', 'smartcars.auto_update');
    Route::group(['middleware' => [SCAuth::class]], function() {
        Route::match(['get', 'options'], '/pilot/statistics', [PilotController::class, 'statistics']);
        Route::group(['prefix' => '/data', 'controller' => DataController::class], function () {
            Route::match(['get', 'options', 'post'], '/aircraft', 'aircraft');
            Route::match(['get', 'options', 'post'], '/airports', 'airports');
            Route::match(['get', 'options'], '/news', 'news');
        });
        Route::group(['prefix' => '/pireps', 'controller' => PirepsController::class], function () {
            Route::match(['get', 'options', 'post'], '/details', 'details');
            Route::match(['get', 'options', 'post'], '/search', 'search');
            Route::match(['get', 'options', 'post'], '/latest', 'latest');
            Route::match(['post', 'options'], '/analyses', [AnalysesController::class, 'upload'])->defaults('scCapability', ['flight-analyses', 'verified-analyses']);
            Route::match(['post', 'options'], '/analyses/search', [AnalysesController::class, 'search'])->defaults('scCapability', ['flight-analyses', 'verified-analyses']);
        });
        Route::group(['prefix' => '/flights', 'controller' => FlightsController::class], function () {
            Route::match(['post', 'options'], '/book', 'book');
            Route::match(['post', 'options'], '/rebook', 'rebook');
            Route::match(['get', 'options'], '/bookings', 'bookings');
            Route::match(['post', 'options'], '/charter', 'charter');
            Route::match(['post', 'options'], '/complete', 'complete');
            Route::match(['post', 'options'], '/cancel', 'cancel');
            Route::match(['post', 'options'], '/divert', 'divert')->defaults('scCapability', 'divert')->defaults('scCapabilitySetting', 'pireps.handle_diversion');
            Route::match(['post', 'options'], '/events', 'events')->defaults('scCapability', 'flight-events');
            Route::match(['post', 'options'], '/start', 'start');
            Route::match(['post', 'options'], '/free/start', 'freeStart')->defaults('scCapability', 'free-flights')->defaults('scCapabilitySetting', 'smartcars.free_flights');
            Route::match(['get', 'options'], '/destinations', 'destinations')->defaults('scCapability', ['flight-destinations', 'destination-routes']);
            Route::match(['get', 'options'], '/search', 'search');
            Route::match(['post', 'options'], '/search/arrivals', 'searchArrivals')->defaults('scCapability', 'arrival-search');
            Route::match(['post', 'options'], '/unbook', 'unbook');
            Route::match(['post', 'options'], '/update', 'update');
        });
    });


});

/**
 * This is required to have a valid API key
 */
