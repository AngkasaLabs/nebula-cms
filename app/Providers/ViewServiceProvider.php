<?php

namespace App\Providers;

use App\Helpers\MenuHelper;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class ViewServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Share menus with all views
        View::composer('theme::*', function ($view) {
            $view->with([
                'headerMenu' => MenuHelper::render('header'),
                'footerMenu' => MenuHelper::render('footer'),
            ]);
        });
    }
}
