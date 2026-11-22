<?php

namespace App\Services;

use App\Models\Plugin;
use App\Support\AuthRedirect;
use App\Support\PluginHooks;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/**
 * Registers optional per-plugin routes.
 *
 * `plugin.json > routes` may be a single group or a list of groups, each with
 * its own prefix, middleware and route file. Example:
 *
 *   "routes": [
 *     {"prefix": "api/store/v1", "middleware": ["api"], "file": "routes/api.php"},
 *     {"prefix": "admin/store", "middleware": ["web", "auth"], "file": "routes/admin.php"}
 *   ]
 *
 * Dynamic registration may not be included in `php artisan route:cache` output;
 * avoid route caching or document limitations.
 */
class PluginRouteRegistrar
{
    /**
     * @param  Collection<int, Plugin>  $plugins
     */
    public function registerForPlugins(Collection $plugins): void
    {
        if ($plugins->isEmpty()) {
            return;
        }

        if (app()->routesAreCached()) {
            Log::warning('Plugin routes are not included in the route cache. Run "php artisan route:clear" or avoid route:cache when using plugins.');

            return;
        }

        foreach ($plugins as $plugin) {
            do_action(PluginHooks::PLUGIN_REGISTER_ROUTES, $plugin);

            foreach ($this->resolveRouteConfigs($plugin) as $routeConfig) {
                $routesFile = $this->resolveRouteFile($plugin, $routeConfig['file']);

                if (! is_file($routesFile)) {
                    continue;
                }

                $group = Route::middleware($routeConfig['middleware']);

                if ($routeConfig['prefix'] !== '') {
                    $group->prefix($routeConfig['prefix']);
                }

                if ($routeConfig['name_prefix'] !== '') {
                    $group->name($routeConfig['name_prefix']);
                }

                $group->group(function () use ($routesFile) {
                    require $routesFile;
                });
            }
        }
    }

    /**
     * @return array<int, array{prefix: string, name_prefix: string, middleware: array<int, string>, file: string}>
     */
    protected function resolveRouteConfigs(Plugin $plugin): array
    {
        $raw = $plugin->getRouteConfigFromDisk();
        $groups = $this->extractRouteGroups($raw);

        $configs = [];

        foreach ($groups as $group) {
            $configs[] = $this->resolveRouteConfig($plugin, $group);
        }

        return $configs;
    }

    /**
     * @param  array<string, mixed>|null  $raw
     * @return array<int, array<string, mixed>>
     */
    protected function extractRouteGroups(?array $raw): array
    {
        if ($raw === null) {
            return [[]];
        }

        // A list of groups: [{...}, {...}]. An empty list falls back to defaults.
        if (array_is_list($raw)) {
            $groups = array_values(array_filter($raw, 'is_array'));

            return $groups === [] ? [[]] : $groups;
        }

        return [$raw];
    }

    /**
     * @param  array<string, mixed>  $group
     * @return array{prefix: string, name_prefix: string, middleware: array<int, string>, file: string}
     */
    protected function resolveRouteConfig(Plugin $plugin, array $group): array
    {
        $config = array_merge([
            'prefix' => '_plugin/'.$plugin->slug,
            'name_prefix' => 'plugin.'.$plugin->slug.'.',
            'middleware' => ['web'],
            'file' => 'routes.php',
        ], $group);

        $config = apply_filters(PluginHooks::PLUGIN_ROUTE_CONFIG, $config, $plugin);

        $middleware = array_values(array_filter(
            is_array($config['middleware'] ?? null) ? $config['middleware'] : ['web'],
            fn ($value) => is_string($value) && $value !== ''
        ));

        if ($middleware === []) {
            $middleware = ['web'];
        }

        $prefix = $this->normalizePrefix($config['prefix'] ?? '_plugin/'.$plugin->slug);

        // Plugin routes mounted under /admin inherit the same gate as the core
        // admin panel, even if the plugin forgot to declare auth middleware.
        if ($this->isAdminPrefix($prefix)) {
            if (! in_array('auth', $middleware, true)) {
                $middleware[] = 'auth';
            }

            $permissionMiddleware = 'permission:'.AuthRedirect::ADMIN_PERMISSION;
            if (! in_array($permissionMiddleware, $middleware, true)) {
                $middleware[] = $permissionMiddleware;
            }
        }

        return [
            'prefix' => $prefix,
            'name_prefix' => $this->normalizeNamePrefix($config['name_prefix'] ?? 'plugin.'.$plugin->slug.'.'),
            'middleware' => $middleware,
            'file' => $this->normalizeRouteFile($config['file'] ?? 'routes.php'),
        ];
    }

    protected function resolveRouteFile(Plugin $plugin, string $file): string
    {
        return base_path("plugins/{$plugin->folder_name}/{$file}");
    }

    protected function normalizeRouteFile(mixed $file): string
    {
        if (! is_string($file) || $file === '') {
            return 'routes.php';
        }

        $file = ltrim(str_replace('\\', '/', $file), '/');

        if ($file === '' || str_contains($file, '..')) {
            return 'routes.php';
        }

        return $file;
    }

    protected function isAdminPrefix(string $prefix): bool
    {
        return $prefix === 'admin' || str_starts_with($prefix, 'admin/');
    }

    protected function normalizePrefix(mixed $prefix): string
    {
        if (! is_string($prefix)) {
            return '';
        }

        return trim($prefix, '/');
    }

    protected function normalizeNamePrefix(mixed $namePrefix): string
    {
        if (! is_string($namePrefix) || $namePrefix === '') {
            return '';
        }

        return str_ends_with($namePrefix, '.') ? $namePrefix : $namePrefix.'.';
    }
}
