<?php

use App\Http\Middleware\AddAgentDiscoveryHeaders;
use App\Http\Middleware\CheckInstallation;
use App\Http\Middleware\EnsureContentPreviewAllowed;
use App\Http\Middleware\EnsurePendingTwoFactorLogin;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\MarkdownNegotiationMiddleware;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(prepend: [
            CheckInstallation::class,
        ]);

        $middleware->web(append: [
            SetLocale::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            AddAgentDiscoveryHeaders::class,
            MarkdownNegotiationMiddleware::class,
        ]);

        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'content.preview' => EnsureContentPreviewAllowed::class,
            'two_factor.pending' => EnsurePendingTwoFactorLogin::class,
        ]);

        // Wizard instalasi: sesi/APP_KEY berubah antar sub-langkah; pengecualian ini aman karena
        // `InstallerController::run` tetap membutuhkan sesi `installer.*` (setelah lock terpasang, rute installer dialihkan).
        $middleware->validateCsrfTokens(except: [
            'install/run',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
