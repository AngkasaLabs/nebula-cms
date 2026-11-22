<?php

namespace App\Services;

use App\Models\Plugin;
use App\Models\Theme;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use ZipArchive;

/**
 * Installs plugin and theme ZIP packages into their target directories.
 *
 * Shared by the manual upload endpoints and (later) the package store so both
 * paths validate and install packages exactly the same way.
 */
class PackageInstaller
{
    public function __construct(
        protected SecureZipInspector $inspector,
    ) {}

    public function installPluginZip(string $zipPath, ?string $expectedSlug = null): Plugin
    {
        $this->inspector->assertSafeArchive($zipPath);
        $this->inspector->assertPluginZipExtensions($zipPath);

        $package = $this->extractPackage($zipPath, 'plugin.json');
        $config = $package['manifest'];
        $slug = $this->validateSlug($config['slug'], $expectedSlug);

        $targetPath = base_path("plugins/{$slug}");

        try {
            if (File::exists($targetPath)) {
                File::deleteDirectory($targetPath);
            }

            File::moveDirectory($package['path'], $targetPath);
        } finally {
            $this->cleanupTemp($package['temp']);
        }

        return Plugin::updateOrCreate(
            ['folder_name' => $slug],
            [
                'name' => $config['name'],
                'slug' => $slug,
                'description' => $config['description'] ?? null,
                'version' => $config['version'],
                'author' => $config['author'] ?? null,
                'author_url' => $config['author_url'] ?? null,
                'settings' => $config['settings'] ?? null,
                'requires' => $config['requires'] ?? null,
            ]
        );
    }

    public function installThemeZip(string $zipPath, ?string $expectedSlug = null): Theme
    {
        $this->inspector->assertSafeArchive($zipPath);
        $this->inspector->assertThemeZipExtensions($zipPath);

        $package = $this->extractPackage($zipPath, 'theme.json');
        $config = $package['manifest'];
        $slug = $this->validateSlug($config['slug'], $expectedSlug);

        $targetPath = base_path("themes/{$slug}");

        try {
            if (File::exists($targetPath)) {
                File::deleteDirectory($targetPath);
            }

            File::moveDirectory($package['path'], $targetPath);
        } finally {
            $this->cleanupTemp($package['temp']);
        }

        $theme = Theme::updateOrCreate(
            ['folder_name' => $slug],
            [
                'name' => $config['name'],
                'slug' => $slug,
                'description' => $config['description'] ?? null,
                'version' => $config['version'],
                'author' => $config['author'] ?? null,
                'settings' => $config['settings'] ?? null,
                'preview' => $config['preview'] ?? null,
            ]
        );

        $this->publishThemeAssets($theme);

        return $theme;
    }

    /**
     * Copy theme assets from themes/{folder}/assets to public/themes/{folder}/assets.
     */
    public function publishThemeAssets(Theme $theme): void
    {
        $lockKey = "theme_assets_{$theme->folder_name}";

        try {
            Cache::lock($lockKey, 30)->get(function () use ($theme) {
                $source = base_path("themes/{$theme->folder_name}/assets");
                $target = public_path("themes/{$theme->folder_name}/assets");

                if (File::exists($source)) {
                    if (File::exists($target)) {
                        File::deleteDirectory($target);
                    }

                    File::copyDirectory($source, $target);
                }
            });
        } catch (LockTimeoutException $e) {
            \Log::error("Failed to acquire lock for theme assets: {$e->getMessage()}");
        }
    }

    /**
     * Extract the archive to a temporary folder and locate its manifest.
     *
     * @return array{manifest: array<string, mixed>, path: string, temp: string}
     */
    protected function extractPackage(string $zipPath, string $manifestName): array
    {
        $tempPath = storage_path('app/temp/'.uniqid('pkg_', true));
        File::makeDirectory($tempPath, 0755, true);

        $zip = new ZipArchive;

        try {
            if ($zip->open($zipPath) !== true) {
                throw new \RuntimeException('Failed to open zip file');
            }

            $zip->extractTo($tempPath);
            $zip->close();

            $packagePath = $this->locateManifest($tempPath, $manifestName);

            if ($packagePath === null) {
                throw new \RuntimeException("Invalid package structure: {$manifestName} not found");
            }

            $config = json_decode(File::get($packagePath.'/'.$manifestName), true);

            if (! is_array($config)
                || ! isset($config['name'])
                || ! isset($config['slug'])
                || ! isset($config['version'])) {
                throw new \RuntimeException("Invalid {$manifestName} structure");
            }

            return [
                'manifest' => $config,
                'path' => $packagePath,
                'temp' => $tempPath,
            ];
        } catch (\Throwable $e) {
            $this->cleanupTemp($tempPath);

            throw $e;
        }
    }

    protected function cleanupTemp(string $path): void
    {
        if (File::exists($path)) {
            File::deleteDirectory($path);
        }
    }

    protected function locateManifest(string $tempPath, string $manifestName): ?string
    {
        if (File::exists($tempPath.'/'.$manifestName)) {
            return $tempPath;
        }

        foreach (File::directories($tempPath) as $directory) {
            if (File::exists($directory.'/'.$manifestName)) {
                return $directory;
            }
        }

        return null;
    }

    protected function validateSlug(mixed $slug, ?string $expectedSlug): string
    {
        if (! is_string($slug) || ! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $slug)) {
            throw new \InvalidArgumentException('Invalid package slug');
        }

        if ($expectedSlug !== null && $slug !== $expectedSlug) {
            throw new \InvalidArgumentException("Package slug [{$slug}] does not match expected [{$expectedSlug}]");
        }

        return $slug;
    }
}
