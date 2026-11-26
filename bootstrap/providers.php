<?php

use App\Providers\AppServiceProvider;
use App\Providers\HookServiceProvider;
use App\Providers\MailSettingsServiceProvider;
use App\Providers\PluginServiceProvider;
use App\Providers\SecuritySettingsServiceProvider;
use App\Providers\ThemeServiceProvider;
use App\Providers\ViewServiceProvider;

return [
    AppServiceProvider::class,
    MailSettingsServiceProvider::class,
    SecuritySettingsServiceProvider::class,
    // Plugin harus sebelum Theme agar add_action('theme.loaded') di plugins/*/index.php
    // terdaftar sebelum do_action('theme.loaded') di ThemeServiceProvider::boot().
    PluginServiceProvider::class,
    ThemeServiceProvider::class,
    ViewServiceProvider::class,
    HookServiceProvider::class,
];
