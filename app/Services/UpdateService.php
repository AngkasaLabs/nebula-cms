<?php

namespace App\Services;

use App\Exceptions\PackageVerificationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use ZipArchive;

class UpdateService
{
    public const CACHE_KEY = 'update_available';

    /**
     * First release that must be signed. Older releases may be applied unsigned
     * so installs running a pre-signing version can update once.
     */
    public const MINIMUM_SIGNED_VERSION = '1.13.0';

    /**
     * Cache key for {@see getLatestReleaseZipInfo()} (public download page).
     */
    public const LATEST_RELEASE_DOWNLOAD_CACHE_KEY = 'nebula_latest_release_download';

    /**
     * Directories/files that belong to the CMS core and will be updated.
     */
    protected array $coreItems = [
        'app',
        'bootstrap',
        'config',
        'database',
        'resources',
        'routes',
        'vendor',
        'artisan',
        'composer.json',
        'composer.lock',
    ];

    /**
     * Paths inside public/ that should be preserved during update.
     */
    protected array $publicPreserved = [
        'themes',
        'storage',
        '.htaccess',
    ];

    /**
     * Return cached update info for Inertia only if it is still newer than the installed version.
     * Stale entries (e.g. after a manual upgrade) are removed from cache.
     *
     * @return array<string, mixed>|null
     */
    public function getSharedUpdateAvailability(): ?array
    {
        $cached = Cache::get(self::CACHE_KEY);

        if (! is_array($cached) || ($cached['available'] ?? false) !== true) {
            return null;
        }

        $current = (string) config('nebula.version');
        $latest = isset($cached['latest']) ? ltrim((string) $cached['latest'], 'v') : '';

        if ($latest === '' || ! version_compare($latest, $current, '>')) {
            Cache::forget(self::CACHE_KEY);

            return null;
        }

        return array_merge($cached, ['current' => $current]);
    }

    /**
     * Clear the cached "update available" payload (e.g. after a successful apply).
     */
    public function forgetCachedUpdateAvailability(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Build a short plain-text excerpt from GitHub's release body (Markdown).
     * The admin UI shows this plus a link to the full release page.
     */
    protected function summarizeReleaseBody(string $body): string
    {
        $body = trim($body);
        if ($body === '') {
            return '';
        }

        $text = preg_replace('/```[\s\S]*?```/', ' ', $body) ?? $body;
        $text = preg_replace('/^#{1,6}\s+.+$/m', ' ', $text);
        $text = preg_replace('/\*\*([^*]+)\*\*/', '$1', $text);
        $text = preg_replace('/`([^`]+)`/', '$1', $text);
        $text = preg_replace('/\[([^\]]+)\]\([^)]+\)/', '$1', $text);
        $text = preg_replace('/^\s*[-*+]\s+/m', ' ', $text);
        $text = preg_replace('/\s+/', ' ', trim($text));

        if ($text === '') {
            return '';
        }

        $max = 280;
        if (mb_strlen($text) <= $max) {
            return $text;
        }

        return rtrim(mb_substr($text, 0, $max - 1)).'…';
    }

    /**
     * Check the GitHub Releases API for a newer version.
     */
    public function checkForUpdate(): array
    {
        $currentVersion = config('nebula.version');
        $repo = config('nebula.github_repo');

        try {
            $response = Http::timeout(15)
                ->withHeaders(['Accept' => 'application/vnd.github+json'])
                ->get("https://api.github.com/repos/{$repo}/releases/latest");

            if ($response->failed()) {
                return [
                    'available' => false,
                    'current' => $currentVersion,
                    'error' => 'Failed to reach GitHub API (HTTP '.$response->status().')',
                ];
            }

            $release = $response->json();
            $latestVersion = ltrim($release['tag_name'] ?? '', 'v');

            // Find the release asset ZIP (not the source zipball) and its signature.
            $downloadUrl = null;
            $signatureUrl = null;
            $zipAssetName = null;
            $signatureAssets = [];

            foreach ($release['assets'] ?? [] as $asset) {
                $name = (string) ($asset['name'] ?? '');

                if ($downloadUrl === null && str_ends_with($name, '.zip')) {
                    $downloadUrl = $asset['browser_download_url'] ?? null;
                    $zipAssetName = $name;
                }

                if (str_ends_with($name, '.sig.json')) {
                    $signatureAssets[$name] = $asset['browser_download_url'] ?? null;
                }
            }

            if ($zipAssetName !== null && isset($signatureAssets[$zipAssetName.'.sig.json'])) {
                $signatureUrl = $signatureAssets[$zipAssetName.'.sig.json'];
            } elseif ($signatureAssets !== []) {
                $signatureUrl = reset($signatureAssets);
            }

            // Fallback to zipball if no asset found
            if (! $downloadUrl) {
                $downloadUrl = $release['zipball_url'] ?? null;
            }

            $isNewer = version_compare($latestVersion, $currentVersion, '>');
            $body = (string) ($release['body'] ?? '');

            return [
                'available' => $isNewer,
                'current' => $currentVersion,
                'latest' => $latestVersion,
                /** Short plain-text excerpt for the admin UI (full notes stay on GitHub). */
                'release_notes' => $this->summarizeReleaseBody($body),
                'release_url' => isset($release['html_url']) ? (string) $release['html_url'] : null,
                'download_url' => $downloadUrl,
                'signature_url' => $signatureUrl,
                'published_at' => $release['published_at'] ?? null,
            ];
        } catch (\Exception $e) {
            Log::error('Update check failed: '.$e->getMessage());

            return [
                'available' => false,
                'current' => $currentVersion,
                'error' => 'Could not check for updates: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Latest GitHub release ZIP URL for the public download page (marketing site).
     * Cached to limit GitHub API usage. The file is the same CI-built archive as on Releases.
     *
     * @return array{
     *     tag: string|null,
     *     version: string|null,
     *     download_url: string|null,
     *     releases_url: string,
     *     html_url: string|null,
     *     published_at: string|null,
     *     error: string|null
     * }
     */
    public function getLatestReleaseZipInfo(): array
    {
        $repo = config('nebula.github_repo');
        $releasesUrl = 'https://github.com/'.$repo.'/releases';

        $cacheRepoKey = str_replace('/', '_', $repo);

        return Cache::remember(self::LATEST_RELEASE_DOWNLOAD_CACHE_KEY.'_'.$cacheRepoKey, 3600, function () use ($repo, $releasesUrl) {
            try {
                $response = Http::timeout(15)
                    ->withHeaders(['Accept' => 'application/vnd.github+json'])
                    ->get("https://api.github.com/repos/{$repo}/releases/latest");

                if ($response->failed()) {
                    return [
                        'tag' => null,
                        'version' => null,
                        'download_url' => null,
                        'releases_url' => $releasesUrl,
                        'html_url' => null,
                        'published_at' => null,
                        'error' => 'GitHub API returned HTTP '.$response->status().'.',
                    ];
                }

                $release = $response->json();
                $tag = $release['tag_name'] ?? null;
                $version = ltrim((string) $tag, 'v');

                $downloadUrl = null;
                foreach ($release['assets'] ?? [] as $asset) {
                    if (str_ends_with((string) ($asset['name'] ?? ''), '.zip')) {
                        $downloadUrl = $asset['browser_download_url'] ?? null;
                        break;
                    }
                }

                if (! $downloadUrl) {
                    $downloadUrl = $release['zipball_url'] ?? null;
                }

                return [
                    'tag' => is_string($tag) ? $tag : null,
                    'version' => $version !== '' ? $version : null,
                    'download_url' => $downloadUrl,
                    'releases_url' => $releasesUrl,
                    'html_url' => $release['html_url'] ?? null,
                    'published_at' => $release['published_at'] ?? null,
                    'error' => null,
                ];
            } catch (\Exception $e) {
                Log::error('Latest release ZIP info failed: '.$e->getMessage());

                return [
                    'tag' => null,
                    'version' => null,
                    'download_url' => null,
                    'releases_url' => $releasesUrl,
                    'html_url' => null,
                    'published_at' => null,
                    'error' => $e->getMessage(),
                ];
            }
        });
    }

    /**
     * Create a backup of core CMS files before updating.
     */
    public function createBackup(): string
    {
        @set_time_limit(0);
        @ini_set('max_execution_time', '600');
        @ignore_user_abort(true);

        $version = config('nebula.version');
        $timestamp = now()->format('Ymd_His');
        $backupDir = storage_path('app/backups');
        $backupFile = $backupDir."/pre-update-{$version}-{$timestamp}.zip";

        if (! File::exists($backupDir)) {
            File::makeDirectory($backupDir, 0755, true);
        }

        $zip = new ZipArchive;

        if ($zip->open($backupFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create backup archive.');
        }

        try {
            // Backup core directories
            foreach ($this->coreItems as $item) {
                $fullPath = base_path($item);

                if (is_dir($fullPath)) {
                    $this->addDirectoryToZip($zip, $fullPath, $item);
                } elseif (is_file($fullPath)) {
                    $zip->addFile($fullPath, $item);
                }
            }

            // Backup public/ core files (excluding preserved paths)
            $publicPath = public_path();
            $this->addDirectoryToZip($zip, $publicPath, 'public', function (string $relativePath) {
                foreach ($this->publicPreserved as $preserved) {
                    if (str_starts_with($relativePath, "public/{$preserved}")) {
                        return false;
                    }
                }

                return true;
            });

            if (! $zip->close()) {
                throw new \RuntimeException('Could not finalize the backup archive.');
            }
        } catch (\Throwable $e) {
            @$zip->close();

            if (File::exists($backupFile)) {
                @unlink($backupFile);
            }

            throw $e;
        }

        Log::info("Backup created: {$backupFile}");

        $this->pruneBackups();

        return $backupFile;
    }

    /**
     * Download and apply an update from the given URL.
     */
    public function applyUpdate(string $downloadUrl, ?string $signatureUrl = null, ?string $expectedVersion = null): array
    {
        $tempDir = storage_path('app/temp/update-'.uniqid());

        @set_time_limit(0);
        @ini_set('max_execution_time', '600');
        @ignore_user_abort(true);

        try {
            File::makeDirectory($tempDir, 0755, true);

            // Download the release ZIP
            $zipPath = $tempDir.'/release.zip';
            $response = Http::timeout(600)->withOptions(['sink' => $zipPath])->get($downloadUrl);

            if (! $response->successful()) {
                throw new \RuntimeException('Download failed (HTTP '.$response->status().').');
            }

            if (! file_exists($zipPath) || filesize($zipPath) === 0) {
                throw new \RuntimeException('Failed to download update file.');
            }

            // Trust check happens before anything is extracted or replaced.
            $manifest = null;

            if (is_string($signatureUrl) && $signatureUrl !== '') {
                $manifest = $this->fetchSignatureManifest($signatureUrl);
            }

            $this->verifyReleaseArchive($zipPath, $manifest, $expectedVersion);

            // Extract ZIP
            $extractDir = $tempDir.'/extracted';
            File::makeDirectory($extractDir, 0755, true);

            $zip = new ZipArchive;
            if ($zip->open($zipPath) !== true) {
                throw new \RuntimeException('Could not open downloaded ZIP.');
            }
            if (! $zip->extractTo($extractDir)) {
                $status = $zip->getStatusString();
                $zip->close();
                throw new \RuntimeException('Could not extract update archive'.($status !== '' ? ': '.$status : '').'.');
            }
            $zip->close();

            // Find the root directory inside the extracted ZIP
            // GitHub zipball wraps content in a sub-directory
            $sourceDir = $extractDir;
            $directories = File::directories($extractDir);
            if (count($directories) === 1 && File::exists($directories[0].'/artisan')) {
                $sourceDir = $directories[0];
            }

            if (! File::exists($sourceDir.'/artisan')) {
                throw new \RuntimeException('Invalid update package: artisan file not found.');
            }

            $oldVersion = config('nebula.version');

            // Overlay core directories
            foreach ($this->coreItems as $item) {
                $sourcePath = $sourceDir.'/'.$item;
                $targetPath = base_path($item);

                if (! File::exists($sourcePath)) {
                    continue;
                }

                if (is_dir($sourcePath)) {
                    $this->replaceDirectorySafely($sourcePath, $targetPath);
                } else {
                    File::copy($sourcePath, $targetPath);
                }
            }

            // Overlay public/ files (excluding preserved)
            $sourcePublic = $sourceDir.'/public';
            if (File::exists($sourcePublic)) {
                $this->overlayPublicDirectory($sourcePublic, public_path());
            }

            // Post-update tasks
            $this->runPostUpdateTasks();

            $newVersion = $this->readVersionFromConfig($sourceDir);

            return [
                'success' => true,
                'message' => 'Update applied successfully.',
                'from' => $oldVersion,
                'to' => $newVersion,
            ];
        } catch (\Throwable $e) {
            Log::error('Update failed: '.$e->getMessage(), ['exception' => $e]);

            return [
                'success' => false,
                'message' => 'Update failed: '.$e->getMessage(),
            ];
        } finally {
            if (File::exists($tempDir)) {
                try {
                    File::deleteDirectory($tempDir);
                } catch (\Throwable $e) {
                    Log::warning('Update temp cleanup failed: '.$e->getMessage(), ['exception' => $e]);
                }
            }
        }
    }

    /**
     * Download and decode the .sig.json asset published next to the release ZIP.
     *
     * @return array<string, mixed>
     */
    protected function fetchSignatureManifest(string $signatureUrl): array
    {
        $response = Http::timeout(30)->get($signatureUrl);

        if (! $response->successful()) {
            throw new PackageVerificationException('Could not download the update signature (HTTP '.$response->status().').');
        }

        $manifest = $response->json();

        if (! is_array($manifest)) {
            throw new PackageVerificationException('Update signature manifest is not valid JSON.');
        }

        return $manifest;
    }

    /**
     * Verify a downloaded core release before it is extracted.
     *
     * Releases from MINIMUM_SIGNED_VERSION onwards must carry a valid signature.
     * Older releases stay installable unsigned so versions before update signing
     * existed can move forward once.
     *
     * @param  array<string, mixed>|null  $manifest
     */
    public function verifyReleaseArchive(string $zipPath, ?array $manifest, ?string $expectedVersion): void
    {
        if ($manifest !== null) {
            $signature = $manifest['signature'] ?? null;

            if (! is_string($signature) || $signature === '') {
                throw new PackageVerificationException('Update manifest is missing the "signature" value.');
            }

            app(CoreReleaseVerifier::class)->verify($zipPath, $manifest, $signature, $expectedVersion);

            return;
        }

        if ($expectedVersion === null || version_compare($expectedVersion, self::MINIMUM_SIGNED_VERSION, '>=')) {
            throw new PackageVerificationException(
                'This update is not signed. Check for updates again; releases from v'.self::MINIMUM_SIGNED_VERSION.' onwards must provide a signature.'
            );
        }

        Log::warning('Applying legacy unsigned core update', ['version' => $expectedVersion]);
    }

    /**
     * Rollback to a previous backup.
     */
    public function rollback(string $backupFile): array
    {
        @set_time_limit(0);
        @ini_set('max_execution_time', '600');
        @ignore_user_abort(true);

        if (! File::exists($backupFile)) {
            return [
                'success' => false,
                'message' => 'Backup file not found.',
            ];
        }

        if (! $this->isValidBackup($backupFile)) {
            return [
                'success' => false,
                'message' => 'Backup archive is invalid or incomplete. Delete it and use an earlier backup.',
            ];
        }

        $tempDir = storage_path('app/temp/rollback-'.uniqid());

        try {
            File::makeDirectory($tempDir, 0755, true);

            $zip = new ZipArchive;
            if ($zip->open($backupFile) !== true) {
                throw new \RuntimeException('Could not open backup archive.');
            }
            $zip->extractTo($tempDir);
            $zip->close();

            // Restore core directories
            foreach ($this->coreItems as $item) {
                $sourcePath = $tempDir.'/'.$item;
                $targetPath = base_path($item);

                if (! File::exists($sourcePath)) {
                    continue;
                }

                if (is_dir($sourcePath)) {
                    $this->replaceDirectorySafely($sourcePath, $targetPath);
                } else {
                    File::copy($sourcePath, $targetPath);
                }
            }

            // Restore public/
            $sourcePublic = $tempDir.'/public';
            if (File::exists($sourcePublic)) {
                $this->overlayPublicDirectory($sourcePublic, public_path());
            }

            // Post-rollback tasks
            $this->runPostUpdateTasks();

            $restoredVersion = config('nebula.version');

            return [
                'success' => true,
                'message' => "Rolled back to version {$restoredVersion}.",
            ];
        } catch (\Throwable $e) {
            Log::error('Rollback failed: '.$e->getMessage(), ['exception' => $e]);

            return [
                'success' => false,
                'message' => 'Rollback failed: '.$e->getMessage(),
            ];
        } finally {
            if (File::exists($tempDir)) {
                try {
                    File::deleteDirectory($tempDir);
                } catch (\Throwable $e) {
                    Log::warning('Rollback temp cleanup failed: '.$e->getMessage(), ['exception' => $e]);
                }
            }
        }
    }

    /**
     * List available backup files.
     *
     * @return list<array{filename: string, path: string, size: int, created_at: string}>
     */
    public function getBackups(): array
    {
        $backupDir = storage_path('app/backups');

        if (! File::exists($backupDir)) {
            return [];
        }

        $files = File::glob($backupDir.'/pre-update-*.zip');

        return collect($files)
            ->filter(fn (string $file) => $this->isValidBackup($file))
            ->map(fn (string $file) => [
                'filename' => basename($file),
                'path' => $file,
                'size' => filesize($file),
                'created_at' => date('Y-m-d H:i:s', filemtime($file)),
            ])
            ->sortByDesc('created_at')
            ->values()
            ->all();
    }

    /**
     * Delete backup archives beyond the configured retention limit.
     * Keeps the newest `nebula.backup_retention` files (minimum 1).
     *
     * @return list<string> Filenames that were deleted.
     */
    public function pruneBackups(?int $keep = null): array
    {
        $keep = max(1, $keep ?? (int) config('nebula.backup_retention', 5));

        $deleted = [];
        $backupDir = storage_path('app/backups');
        $staleCutoff = now()->subHour()->timestamp;

        // Remove incomplete archives left behind by an aborted backup.
        foreach (File::glob($backupDir.'/pre-update-*.zip') ?: [] as $file) {
            if (filemtime($file) < $staleCutoff && ! $this->isValidBackup($file)) {
                if (@unlink($file)) {
                    $deleted[] = basename($file);
                }
            }
        }

        foreach (array_slice($this->getBackups(), $keep) as $backup) {
            try {
                if (File::delete($backup['path'])) {
                    $deleted[] = $backup['filename'];
                }
            } catch (\Throwable $e) {
                Log::warning('Could not prune backup: '.$e->getMessage(), ['path' => $backup['path']]);
            }
        }

        return $deleted;
    }

    /**
     * Delete a single backup archive, rejecting paths outside the backups directory.
     */
    public function deleteBackup(string $backupFile): bool
    {
        $backupsDir = realpath(storage_path('app/backups'));
        $resolved = realpath($backupFile);

        if (! $backupsDir
            || ! $resolved
            || ! str_starts_with($resolved, $backupsDir.DIRECTORY_SEPARATOR)
            || ! is_file($resolved)
        ) {
            return false;
        }

        return File::delete($resolved);
    }

    /**
     * Run post-update/rollback maintenance tasks.
     */
    protected function runPostUpdateTasks(): void
    {
        try {
            \Artisan::call('migrate', ['--force' => true]);
        } catch (\Exception $e) {
            Log::warning('Post-update migration failed: '.$e->getMessage());
        }

        try {
            // Clears config, route, view, event and application caches.
            \Artisan::call('optimize:clear');
        } catch (\Exception $e) {
            Log::warning('Post-update cache clear failed: '.$e->getMessage());
        }
    }

    /**
     * Replace a directory without deleting the live tree until a full copy succeeds.
     * Copies to a staging directory, renames the old tree aside, then promotes staging.
     */
    protected function replaceDirectorySafely(string $sourcePath, string $targetPath): void
    {
        if (! is_dir($sourcePath)) {
            throw new \InvalidArgumentException('replaceDirectorySafely: source must be a directory.');
        }

        $parent = dirname($targetPath);
        $base = basename($targetPath);
        $staging = $parent.'/__nebula_staging_'.$base.'_'.uniqid('', true);
        $previous = $parent.'/__nebula_previous_'.$base.'_'.uniqid('', true);

        File::copyDirectory($sourcePath, $staging);

        $hadTarget = File::exists($targetPath);
        if ($hadTarget) {
            if (! @rename($targetPath, $previous)) {
                File::deleteDirectory($staging);

                throw new \RuntimeException('Could not stage existing directory for replacement: '.$base);
            }
        }

        if (! @rename($staging, $targetPath)) {
            if ($hadTarget && File::exists($previous)) {
                @rename($previous, $targetPath);
            }
            if (File::exists($staging)) {
                File::deleteDirectory($staging);
            }

            throw new \RuntimeException('Could not activate new directory: '.$base);
        }

        if ($hadTarget && File::exists($previous)) {
            try {
                File::deleteDirectory($previous);
            } catch (\Throwable $e) {
                Log::warning('Could not remove previous directory after replace: '.$e->getMessage(), ['path' => $previous]);
            }
        }
    }

    /**
     * Overlay public directory, skipping preserved paths.
     */
    protected function overlayPublicDirectory(string $source, string $target): void
    {
        $items = File::allFiles($source);

        foreach ($items as $file) {
            $relativePath = $file->getRelativePath();

            // Check if this path is preserved
            $skip = false;
            foreach ($this->publicPreserved as $preserved) {
                if (str_starts_with($relativePath, $preserved)) {
                    $skip = true;
                    break;
                }
            }

            if ($skip) {
                continue;
            }

            $targetFile = $target.'/'.$file->getRelativePathname();
            $targetDir = dirname($targetFile);

            if (! File::exists($targetDir)) {
                File::makeDirectory($targetDir, 0755, true);
            }

            File::copy($file->getRealPath(), $targetFile);
        }
    }

    /**
     * Read version from the downloaded config file.
     */
    protected function readVersionFromConfig(string $sourceDir): string
    {
        $configFile = $sourceDir.'/config/nebula.php';

        if (File::exists($configFile)) {
            $config = include $configFile;

            return $config['version'] ?? 'unknown';
        }

        return 'unknown';
    }

    /**
     * Recursively add a directory to a ZipArchive.
     *
     * Uses a streaming recursive iterator instead of File::allFiles(), which
     * sorts the whole tree first. On shared hosting that sort is what pushed
     * update requests past the 30 second execution limit.
     */
    protected function addDirectoryToZip(ZipArchive $zip, string $path, string $zipPath, ?\Closure $filter = null): void
    {
        $root = rtrim($path, '/\\');

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile() || $file->isLink()) {
                continue;
            }

            $absolutePath = $file->getPathname();
            $relativePath = str_replace('\\', '/', substr($absolutePath, strlen($root) + 1));
            $zipRelative = $zipPath.'/'.$relativePath;

            if ($filter && ! $filter($zipRelative)) {
                continue;
            }

            $zip->addFile($absolutePath, $zipRelative);
        }
    }

    /**
     * A backup is usable only when the ZIP central directory is readable.
     */
    protected function isValidBackup(string $file): bool
    {
        if (! is_file($file) || filesize($file) < 64) {
            return false;
        }

        $zip = new ZipArchive;
        $opened = $zip->open($file, ZipArchive::CHECKCONS);

        if ($opened === true) {
            $zip->close();
        }

        return $opened === true;
    }
}
