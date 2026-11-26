<?php

namespace App\Providers;

use App\Support\MailSettings;
use Illuminate\Support\ServiceProvider;

/**
 * Applies admin-managed SMTP settings over the environment configuration.
 */
class MailSettingsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        MailSettings::applyToConfig();
    }
}
