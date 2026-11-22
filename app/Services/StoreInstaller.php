<?php

namespace App\Services;

use App\Exceptions\PackageVerificationException;
use App\Exceptions\StoreException;
use Composer\Semver\Semver;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * Downloads, verifies, and installs a package from the registry.
 *
 * The registry is never trusted on its own: the signature and checksum are
 * validated with PackageVerifier before anything touches the filesystem.
 */
class StoreInstaller
{
    public function __construct(
        protected StoreClient $client,
        protected PackageVerifier $verifier,
        protected PackageInstaller $installer,
    ) {}

    /**
     * @return array{name: string, slug: string, type: string, version: string}
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

        try {
            $this->verifier->verify($archive, $manifest, $signature);

            $model = $type === 'theme'
                ? $this->installer->installThemeZip($archive, $slug)
                : $this->installer->installPluginZip($archive, $slug);
        } finally {
            if (File::exists($archive)) {
                File::delete($archive);
            }
        }

        return [
            'name' => (string) $model->name,
            'slug' => $slug,
            'type' => $type,
            'version' => (string) ($manifest['version'] ?? $model->version),
        ];
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
