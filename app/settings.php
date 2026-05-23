<?php

use App\Services\SiteSettings;

if (! function_exists('settings')) {
    /**
     * Get a site setting by dot-notation key.
     *
     * Returns the whole SiteSettings instance when called with no arguments,
     * which lets you chain: settings()->group('fees')
     *
     * @param  string|null  $key       'fees.creation_fee_pct'
     * @param  mixed        $fallback  Returned if the key is missing
     * @return mixed|SiteSettings
     */
    function settings(?string $key = null, mixed $fallback = null): mixed
    {
        $service = app(SiteSettings::class);

        if ($key === null) {
            return $service;
        }

        return $service->get($key, $fallback);
    }
}

if (! function_exists('setting_enabled')) {
    /**
     * Shorthand boolean check for toggle settings.
     *
     * settings_enabled('platform.maintenance_mode')  // true|false
     */
    function setting_enabled(string $key): bool
    {
        return app(SiteSettings::class)->isEnabled($key);
    }
}