<?php

use App\Models\Plugin;
use App\Services\PluginRouteRegistrar;
use Illuminate\Support\Facades\File;

it('registers multiple route groups per plugin with separate files', function () {
    $folder = '__plugin_multi_routes_'.uniqid();
    $path = base_path("plugins/{$folder}");

    File::makeDirectory($path.'/routes', 0755, true);
    File::put($path.'/plugin.json', json_encode([
        'name' => 'Multi Route Plugin',
        'slug' => $folder,
        'version' => '1.0.0',
        'routes' => [
            [
                'prefix' => '_test/multi',
                'name_prefix' => 'multitest',
                'middleware' => ['web'],
                'file' => 'routes/web.php',
            ],
            [
                'prefix' => 'api/test-multi',
                'name_prefix' => 'multitest.api',
                'middleware' => ['api'],
                'file' => 'routes/api.php',
            ],
        ],
    ]));
    File::put($path.'/routes/web.php', <<<'PHP'
<?php


Route::get('/', fn () => response('web-ok'))->name('web');
PHP);
    File::put($path.'/routes/api.php', <<<'PHP'
<?php


Route::post('/echo', fn () => response()->json(['ok' => true]))->name('echo');
PHP);

    try {
        $plugin = new Plugin([
            'name' => 'Multi Route Plugin',
            'slug' => $folder,
            'folder_name' => $folder,
            'version' => '1.0.0',
        ]);

        app(PluginRouteRegistrar::class)->registerForPlugins(collect([$plugin]));

        $routes = app('router')->getRoutes();
        $names = collect($routes->getRoutes())->map(fn ($route) => $route->getName())->filter()->values()->all();

        expect($names)->toContain('multitest.web');
        expect($names)->toContain('multitest.api.echo');

        $this->get('/_test/multi')->assertSee('web-ok');

        // The api group is stateless, so a POST must work without a CSRF token.
        $this->postJson('/api/test-multi/echo')->assertOk()->assertJson(['ok' => true]);
    } finally {
        if (File::exists($path)) {
            File::deleteDirectory($path);
        }
    }
});
