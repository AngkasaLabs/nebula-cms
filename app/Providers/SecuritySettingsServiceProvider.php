<?php

namespace App\Providers;

use App\Support\SecuritySettings;
use Illuminate\Support\ServiceProvider;

/**
 * Applies admin-managed security switches (CSP enforcement) over the config.
 */
class SecuritySettingsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        SecuritySettings::applyToConfig();
    }
}
