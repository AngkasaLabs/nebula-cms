<?php

namespace App\Services;

use App\Exceptions\PackageVerificationException;
use App\Exceptions\StoreException;
use App\Models\Plugin;
use App\Models\Theme;
use Composer\Semver\Semver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Downloads, verifies, and installs a package from the registry.
 *
 * The registry is never trusted on its own: the signature and checksum are
 * validated with PackageVerifier before anything touches the filesystem.
 * Updates snapshot the installed folder and roll it back if installation
 * fails.
 */
class StoreInstaller
{
    public function __construct(
        protected StoreClient $client,
        protected PackageVerifier $verifier,
        protected PackageInstaller $installer,
        protected PackageBackups $backups,
    ) {}

    /**
     * @return array{name: string, slug: string, type: string, version: string, backup_created: bool}
     */
    public function install(string $slug, ?string $version = null): array
    {
        $download = $this->client->download($slug, $version);

        $manifest = $download['manifest'];
        $signature = $download['signature'];

        if (($manifest['slug'] ?? null) !== $slug) {
            throw new StoreException('The store returned a manifest for a different package.');
        }

        $type = (string) ($manifest['type'] ?? '');

        if (! in_array($type, ['plugin', 'theme'], true)) {
            throw new StoreException('The store returned an unknown package type.');
        }

        $this->assertCompatible($manifest);

        $this->verifier->verifyManifest($manifest, $signature, $manifest['key_id'] ?? null);

        $archive = $this->downloadArchive($download['url'], $manifest);
        $existing = $this->installedModel($type, $slug);
        $snapshot = $existing ? $this->snapshot($existing, $type) : null;
        $backupPath = null;

        try {
            $this->verifier->verify($archive, $manifest, $signature);

            if ($existing) {
                $backupPath = $this->backups->create($type, $slug);
            }

            $model = $type === 'theme'
                ? $this->installer->installThemeZip($archive, $slug)
                : $this->installer->installPluginZip($archive, $slug);
        } catch (\Throwable $e) {
            $this->handleFailure($type, $slug, $existing, $snapshot, $backupPath);

            if ($e instanceof StoreException || $e instanceof PackageVerificationException) {
                throw $e;
            }

            throw new StoreException('Installation failed: '.$e->getMessage(), previous: $e);
        } finally {
            if (File::exists($archive)) {
                File::delete($archive);
            }
        }

        try {
            $this->backups->prune($type, $slug);
        } catch (\Throwable $e) {
            report($e);
        }

        return [
            'name' => (string) $model->name,
            'slug' => $slug,
            'type' => $type,
            'version' => (string) ($manifest['version'] ?? $model->version),
            'backup_created' => $backupPath !== null,
        ];
    }

    protected function installedModel(string $type, string $slug): Plugin|Theme|null
    {
        return $type === 'theme'
            ? Theme::query()->where('folder_name', $slug)->first()
            : Plugin::query()->where('folder_name', $slug)->first();
    }

    /**
     * @return array<string, mixed>
     */
    protected function snapshot(Model $model, string $type): array
    {
        $fields = $type === 'theme'
            ? ['name', 'slug', 'description', 'version', 'author', 'preview', 'settings', 'is_active']
            : ['name', 'slug', 'description', 'version', 'author', 'author_url', 'requires', 'settings', 'is_active'];

        $snapshot = [];

        foreach ($fields as $field) {
            $snapshot[$field] = $model->getAttribute($field);
        }

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>|null  $snapshot
     */
    protected function handleFailure(
        string $type,
        string $slug,
        ?Model $existing,
        ?array $snapshot,
        ?string $backupPath,
    ): void {
        if ($existing !== null) {
            if ($backupPath === null || $snapshot === null) {
                // The failure happened before anything was replaced.
                return;
            }

            try {
                $this->backups->restore($type, $slug, $backupPath);
                $existing->forceFill($snapshot)->save();

                if ($type === 'theme') {
                    $fresh = $existing->fresh();
                    if ($fresh) {
                        $this->installer->publishThemeAssets($fresh);
                    }
                }

                Log::warning("Store update of {$type} [{$slug}] failed; restored the previous backup.");

                return;
            } catch (\Throwable $rollbackError) {
                Log::error("Store update rollback for {$type} [{$slug}] failed: {$rollbackError->getMessage()}");

                throw new StoreException(
                    'The update failed and automatic rollback also failed. Restore manually from '
                    .$backupPath.'.',
                    previous: $rollbackError
                );
            }
        }

        // Fresh install failed: remove whatever was partially written.
        $folder = $type === 'theme' ? 'themes' : 'plugins';
        $path = base_path("{$folder}/{$slug}");

        if (File::isDirectory($path)) {
            File::deleteDirectory($path);
        }

        if ($type === 'theme') {
            Theme::query()->where('folder_name', $slug)->delete();
        } else {
            Plugin::query()->where('folder_name', $slug)->delete();
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    protected function assertCompatible(array $manifest): void
    {
        $requires = $manifest['requires'] ?? null;

        if (! is_array($requires)) {
            return;
        }

        $constraint = $requires['cms_version'] ?? null;

        if (! is_string($constraint) || $constraint === '') {
            return;
        }

        $cmsVersion = (string) config('nebula.version', '0.0.0');

        try {
            $compatible = Semver::satisfies($cmsVersion, $constraint);
        } catch (\Throwable) {
            throw new StoreException("Package requires an invalid cms_version constraint [{$constraint}].");
        }

        if (! $compatible) {
            throw new StoreException(
                "Package requires NebulaCMS {$constraint}; this site runs {$cmsVersion}."
            );
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    protected function downloadArchive(string $url, array $manifest): string
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new StoreException('The store returned an invalid download URL.');
        }

        $expectedSize = isset($manifest['size']) ? (int) $manifest['size'] : null;

        if ($expectedSize !== null && ($expectedSize <= 0 || $expectedSize > 50 * 1024 * 1024)) {
            throw new StoreException('The package size is outside the allowed range.');
        }

        $directory = storage_path('app/temp');
        File::ensureDirectoryExists($directory);

        $path = $directory.'/store-'.uniqid('', true).'.zip';

        try {
            $response = Http::timeout(120)->get($url);
        } catch (\Throwable $e) {
            @unlink($path);

            throw new StoreException('Failed to download the package archive.', previous: $e);
        }

        if (! $response->successful()) {
            @unlink($path);

            throw new StoreException('Failed to download the package archive.');
        }

        File::put($path, $response->body());

        if (! is_file($path)) {
            throw new StoreException('Failed to store the downloaded archive.');
        }

        if ($expectedSize !== null && filesize($path) !== $expectedSize) {
            @unlink($path);

            throw new PackageVerificationException('Downloaded archive size does not match the signed manifest.');
        }

        return $path;
    }
}
