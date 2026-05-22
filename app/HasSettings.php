<?php

namespace App;

use App\Models\Settings;

trait HasSettings
{
    public function settings()
    {
        return $this->morphMany(Settings::class, 'settingable');
    }


    /**
     * Update a setting. Supports merging arrays for grouped settings (e.g., notifications).
     */
    public function updateSetting(string $key, $value)
    {
        // If value is array and setting exists, merge
        $setting = $this->settings()->where('key', $key)->first();
        if (is_array($value) && $setting) {
            $merged = array_merge($setting->value ?? [], $value);
            return $this->settings()->updateOrCreate(
                ['key' => $key],
                ['value' => $merged]
            );
        }
        return $this->settings()->updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }

    /**
     * Get a setting value. Supports dot notation for nested settings.
     */
    public function getSetting(string $key, $default = null)
    {
        if (str_contains($key, '.')) {
            [$main, $sub] = explode('.', $key, 2);
            $setting = $this->settings()->where('key', $main)->first();
            if ($setting && is_array($setting->value)) {
                return data_get($setting->value, $sub, $default);
            }
            return $default;
        } else {
            $setting = $this->settings()->where('key', $key)->first();
            return $setting ? $setting->value : $default;
        }
    }
}
