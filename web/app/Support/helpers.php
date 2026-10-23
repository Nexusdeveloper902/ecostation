<?php

use App\Services\SettingsService;

if (! function_exists('settings')) {
    function settings(): SettingsService
    {
        return app(SettingsService::class);
    }
}
