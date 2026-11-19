<?php

namespace Modules\SmartCARS3phpVMS7Api\Support;

use Illuminate\Support\Facades\Route;

/**
 * The optional features this backend supports, declared on routes as scCapability defaults.
 */
class Capabilities
{
    private const ROUTE_PREFIX = 'api/smartcars';

    /**
     * scCapability is a capability or a list of them; scCapabilitySetting is the setting gating a single
     * capability, or a map from capability to setting for a list.
     */
    public static function allowed(array $routeDefaults): array
    {
        $capability = $routeDefaults['scCapability'] ?? null;
        if ($capability === null) {
            return [];
        }
        $setting = $routeDefaults['scCapabilitySetting'] ?? null;
        $capabilities = (array) $capability;
        $settings = is_array($setting) ? $setting : array_fill_keys($capabilities, $setting);

        return array_values(array_filter(
            $capabilities,
            fn ($name) => ($settings[$name] ?? null) === null || setting($settings[$name], false)
        ));
    }

    public static function all(): array
    {
        $capabilities = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (str_starts_with($route->uri(), self::ROUTE_PREFIX)) {
                $capabilities = array_merge($capabilities, self::allowed($route->defaults));
            }
        }
        $capabilities = array_unique($capabilities);
        sort($capabilities);

        return array_values($capabilities);
    }
}
