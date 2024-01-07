<?php

namespace Modules\SmartcARS3phpVMS7Api\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * Class SCAuth
 * @package Modules\SmartCARSvms7\Http\Middleware
 */
class SCHeaders
{
    /**
     * Handle an incoming request.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @return mixed
     */
    public function handle(Request $request, Closure $next)
    {
        $method = $request->method();
        //dd($method);
        if ($method === 'OPTIONS' || $method === 'HEAD')
        {
            $response = response()->json(null);
        }
        else {
            $response = $next($request);
        }
        //dd($response);
        $response->withHeaders([
            'Content-type' => 'application/json',
            'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS, HEAD',
            'Access-Control-Allow-Headers' => 'Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With',
            'Access-Control-Allow-Origin' => '*'
        ]);
        return $response;

    }
}
