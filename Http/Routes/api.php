<?php
use \Modules\SmartCARS3phpVMS7Api\Http\Controllers\Api\PilotController;
use \Modules\SmartCARS3phpVMS7Api\Http\Controllers\Api\DataController;
use \Modules\SmartCARS3phpVMS7Api\Http\Controllers\Api\PirepsController;
use \Modules\SmartCARS3phpVMS7Api\Http\Controllers\Api\FlightsController;
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
        return response()->json(["apiVersion" => $version, "handler" => "phpvms7"]);
    });
    Route::match(['post', 'options'], '/pilot/login', [PilotController::class, 'login']);
    Route::match(['post', 'options'], '/pilot/resume', [PilotController::class, 'resume']);
    Route::match(['post', 'options'], '/pilot/verify', [PilotController::class, 'verify']);
    Route::group(['middleware' => [SCAuth::class]], function() {
        Route::match(['get', 'options'], '/pilot/statistics', [PilotController::class, 'statistics']);
        Route::group(['prefix' => '/data', 'controller' => DataController::class], function () {
            Route::match(['get', 'options', 'post'], '/aircraft', 'aircraft');
            Route::match(['get', 'options', 'post'], '/airports', 'airports');
            Route::match(['get', 'options'], '/subfleets', 'subfleets');
            Route::match(['get', 'options'], '/news', 'news');
            Route::match(['get', 'options'], '/flight_types', 'flightTypes');
        });
        Route::group(['prefix' => '/pireps', 'controller' => PirepsController::class], function () {
            Route::match(['get', 'options', 'post'], '/details', 'details');
            Route::match(['get', 'options', 'post'], '/search', 'search');
            Route::match(['get', 'options', 'post'], '/latest', 'latest');
        });
        Route::group(['prefix' => '/flights', 'controller' => FlightsController::class], function () {
            Route::match(['post', 'options'], '/book', 'book');
            Route::match(['post', 'options'], '/rebook', 'rebook');
            Route::match(['get', 'options'], '/bookings', 'bookings');
            Route::match(['post', 'options'], '/charter', 'charter');
            Route::match(['post', 'options'], '/complete', 'complete');
            Route::match(['post', 'options'], '/cancel', 'cancel');
            Route::match(['post', 'options'], '/start', 'start');
            Route::match(['get', 'options'], '/search', 'search');
            Route::match(['post', 'options'], '/unbook', 'unbook');
            Route::match(['post', 'options'], '/update', 'update');
        });
    });


});

/**
 * This is required to have a valid API key
 */
