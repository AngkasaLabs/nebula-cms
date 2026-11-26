<?php

namespace App\Services;

use App\Services\Backup\DatabaseDumper;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use ZipArchive;

/**
 * Full-site backups: application files, themes, plugins, uploads and a
 * database dump in one ZIP, with a restore path that can be triggered from the
 * admin panel.
 *
 * Vendor, node_modules, logs, caches, the `.env` file and the backups folder
 * are intentionally excluded. A restore keeps the current `.env` so database
 * credentials and the application key stay valid for this installation.
 */
class BackupService
{
    public const MANIFEST = 'backup-manifest.json';

    public const FORMAT = 1;

    /** Directories restored from the ZIP, relative to the project root. */
    private const DIRECTORIES = [
        'app',
        'bootstrap',
        'config',
        'database',
        'resources',
        'routes',
        'themes',
        'plugins',
        'public',
        'storage/app/public',
    ];

    /** Individual files restored from the ZIP. */
    private const FILES = [
        'artisan',
        'composer.json',
        'composer.lock',
    ];

    public function __construct(
        private readonly DatabaseDumper $dumper,
        private readonly ?string $root = null,
        private readonly ?string $backupDirectory = null,
    ) {}

    public function root(): string
    {
        return rtrim($this->root ?? base_path(), '/');
    }

    public function backupDirectory(): string
    {
        return $this->backupDirectory ?? storage_path('app/backups');
    }

    /**
     * @return array{filename: string, path: string, size: int, created_at: string}
     */
    public function create(string $type = 'manual', bool $prune = true): array
    {
        @set_time_limit(0);
        @ini_set('max_execution_time', '1800');
        @ignore_user_abort(true);

        $type = preg_replace('/[^a-z0-9-]/', '', strtolower($type)) ?: 'manual';
        $directory = $this->backupDirectory();
        File::ensureDirectoryExists($directory);

        $filename = 'backup-'.$type.'-'.now()->format('Ymd_His').'.zip';
        $path = $directory.'/'.$filename;

        if (File::exists($path)) {
            $filename = 'backup-'.$type.'-'.now()->format('Ymd_His').'-'.substr(uniqid(), -4).'.zip';
            $path = $directory.'/'.$filename;
        }

        $temp = storage_path('app/temp/backup-'.uniqid('', true));
        File::ensureDirectoryExists($temp);

        try {
            $database = $this->dumper->dump($temp);

            $manifest = [
                'format' => self::FORMAT,
                'type' => $type,
                'created_at' => now()->toIso8601String(),
                'app_version' => (string) config('nebula.version'),
                'db_driver' => $database['driver'],
                'db_dump' => $database['file'],
            ];

            $zip = new ZipArchive;

            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not create the backup archive.');
            }

            try {
                $zip->addFromString(self::MANIFEST, json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
                $zip->addFile($temp.'/'.$database['file'], $database['file']);

                foreach (self::DIRECTORIES as $relative) {
                    $absolute = $this->root().'/'.$relative;

                    if (is_dir($absolute)) {
                        $this->addDirectory($zip, $absolute, $relative);
                    }
                }

                foreach (self::FILES as $relative) {
                    $absolute = $this->root().'/'.$relative;

                    if (is_file($absolute)) {
                        $zip->addFile($absolute, $relative);
                    }
                }

                if (! $zip->close()) {
                    throw new RuntimeException('Could not finalize the backup archive.');
                }
            } catch (\Throwable $e) {
                @$zip->close();

                if (File::exists($path)) {
                    @unlink($path);
                }

                throw $e;
            }

            Log::info('Full backup created.', ['path' => $path]);

            if ($prune) {
                $this->prune();
            }

            return [
                'filename' => $filename,
                'path' => $path,
                'size' => (int) filesize($path),
                'created_at' => now()->format('Y-m-d H:i:s'),
            ];
        } finally {
            if (File::exists($temp)) {
                File::deleteDirectory($temp);
            }
        }
    }

    /**
     * @return list<array{filename: string, path: string, size: int, created_at: string, type: string, app_version: string, db_driver: string}>
     */
    public function listBackups(): array
    {
        $directory = $this->backupDirectory();

        if (! File::isDirectory($directory)) {
            return [];
        }

        return collect(File::glob($directory.'/backup-*.zip') ?: [])
            ->map(function (string $file): ?array {
                $manifest = $this->readManifest($file);

                if ($manifest === null) {
                    return null;
                }

                return [
                    'filename' => basename($file),
                    'path' => $file,
                    'size' => (int) filesize($file),
                    'created_at' => date('Y-m-d H:i:s', (int) filemtime($file)),
                    'type' => (string) ($manifest['type'] ?? 'manual'),
                    'app_version' => (string) ($manifest['app_version'] ?? ''),
                    'db_driver' => (string) ($manifest['db_driver'] ?? ''),
                ];
            })
            ->filter()
            ->sortByDesc('created_at')
            ->values()
            ->all();
    }

    public function delete(string $backupPath): bool
    {
        $resolved = realpath($backupPath);
        $directory = realpath($this->backupDirectory());

        if (! $resolved || ! $directory
            || ! str_starts_with($resolved, $directory.DIRECTORY_SEPARATOR)
            || ! is_file($resolved)
        ) {
            return false;
        }

        return File::delete($resolved);
    }

    /**
     * Restore files and the database from a backup archive.
     *
     * @return array{success: bool, message: string}
     */
    public function restore(string $backupPath): array
    {
        @set_time_limit(0);
        @ini_set('max_execution_time', '1800');
        @ignore_user_abort(true);

        $manifest = $this->readManifest($backupPath);

        if ($manifest === null) {
            return ['success' => false, 'message' => 'This file is not a valid NebulaCMS backup.'];
        }

        $temp = storage_path('app/temp/restore-'.uniqid('', true));

        try {
            // Always keep an escape hatch before overwriting the running site.
            $this->create('pre-restore', false);

            File::ensureDirectoryExists($temp);

            $zip = new ZipArchive;

            if ($zip->open($backupPath) !== true) {
                throw new RuntimeException('Could not open the backup archive.');
            }

            if (! $zip->extractTo($temp)) {
                $status = $zip->getStatusString();
                $zip->close();

                throw new RuntimeException('Could not extract the backup archive'.($status !== '' ? ': '.$status : '').'.');
            }

            $zip->close();

            foreach (self::DIRECTORIES as $relative) {
                $source = $temp.'/'.$relative;
                $target = $this->root().'/'.$relative;

                if (! File::isDirectory($source)) {
                    continue;
                }

                File::ensureDirectoryExists($target);
                File::copyDirectory($source, $target);
            }

            foreach (self::FILES as $relative) {
                $source = $temp.'/'.$relative;

                if (is_file($source)) {
                    File::copy($source, $this->root().'/'.$relative);
                }
            }

            $this->dumper->restore(
                $temp.'/'.(string) $manifest['db_dump'],
                (string) $manifest['db_driver'],
            );

            Artisan::call('optimize:clear');

            try {
                Artisan::call('migrate', ['--force' => true]);
            } catch (\Throwable $e) {
                Log::warning('Backup restore: post-restore migration failed.', ['error' => $e->getMessage()]);
            }

            Log::warning('Full backup restored.', ['path' => $backupPath]);

            return ['success' => true, 'message' => 'Backup restored. Files and database now match '.($manifest['created_at'] ?? 'the archive').'.'];
        } catch (\Throwable $e) {
            Log::error('Backup restore failed.', ['path' => $backupPath, 'error' => $e->getMessage()]);

            return ['success' => false, 'message' => 'Restore failed: '.$e->getMessage()];
        } finally {
            if (File::exists($temp)) {
                File::deleteDirectory($temp);
            }
        }
    }

    /**
     * @return list<string>
     */
    public function prune(?int $keep = null): array
    {
        $keep = max(1, $keep ?? (int) config('nebula.backup_retention', 5));
        $deleted = [];

        foreach (array_slice($this->listBackups(), $keep) as $backup) {
            if (File::delete($backup['path'])) {
                $deleted[] = $backup['filename'];
            }
        }

        return $deleted;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function readManifest(string $file): ?array
    {
        if (! is_file($file) || filesize($file) < 64) {
            return null;
        }

        $zip = new ZipArchive;

        if ($zip->open($file, ZipArchive::CHECKCONS) !== true) {
            return null;
        }

        $raw = $zip->getFromName(self::MANIFEST);
        $hasDump = false;

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $hasDump = is_array($decoded)
                && ($decoded['format'] ?? 0) === self::FORMAT
                && is_string($decoded['db_dump'] ?? null)
                && $zip->locateName($decoded['db_dump']) !== false;
        }

        $zip->close();

        if (! is_string($raw) || ! $hasDump) {
            return null;
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    private function addDirectory(ZipArchive $zip, string $absolute, string $relative): void
    {
        $root = rtrim($absolute, '/\\');

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            if (! $file instanceof \SplFileInfo || ! $file->isFile() || $file->isLink()) {
                continue;
            }

            $path = $file->getPathname();
            $relativePath = str_replace('\\', '/', substr($path, strlen($root) + 1));
            $zipPath = $relative.'/'.$relativePath;

            if ($this->isExcluded($zipPath)) {
                continue;
            }

            $zip->addFile($path, $zipPath);
        }
    }

    private function isExcluded(string $path): bool
    {
        foreach ([
            '/node_modules/',
            '/vendor/',
            '/.git/',
            '/storage/framework/',
            '/storage/logs/',
            '/storage/app/backups/',
            '/storage/app/temp/',
            '/public/storage/',
            '/__nebula_staging_',
            '/__nebula_previous_',
        ] as $needle) {
            if (str_contains('/'.$path.'/', $needle)) {
                return true;
            }
        }

        return str_ends_with($path, '.sqlite')
            || str_ends_with($path, '.sqlite-journal')
            || str_ends_with($path, '.sqlite-wal')
            || str_ends_with($path, '.sqlite-shm');
    }
}
