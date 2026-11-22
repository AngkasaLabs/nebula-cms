<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use ZipArchive;

/**
 * Creates and restores ZIP snapshots of installed plugin/theme folders.
 *
 * Backups are used before a store update so a failed install can be rolled
 * back to the previous working files.
 */
class PackageBackups
{
    public function directory(): string
    {
        $path = storage_path('app/backups/packages');
        File::ensureDirectoryExists($path);

        return $path;
    }

    /**
     * @param  string  $type  "plugin" or "theme"
     */
    public function create(string $type, string $slug): string
    {
        $source = $this->packagePath($type, $slug);

        if (! File::isDirectory($source)) {
            throw new \RuntimeException("Package folder for [{$slug}] does not exist.");
        }

        $path = $this->directory().'/'.sprintf(
            '%s-%s-%s-%s.zip',
            $type,
            $slug,
            now()->format('YmdHis'),
            substr(bin2hex(random_bytes(3)), 0, 6)
        );

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Unable to create the package backup archive.');
        }

        foreach (File::allFiles($source) as $file) {
            $relative = str_replace('\\', '/', $file->getRelativePathname());
            $zip->addFile($file->getPathname(), $relative);
        }

        $zip->close();

        if (! is_file($path) || filesize($path) === 0) {
            @unlink($path);

            throw new \RuntimeException('The package backup archive is empty.');
        }

        return $path;
    }

    public function restore(string $type, string $slug, string $backupPath): void
    {
        if (! is_file($backupPath)) {
            throw new \RuntimeException('Package backup archive not found.');
        }

        $target = $this->packagePath($type, $slug);
        $temp = storage_path('app/temp/restore-'.uniqid('', true));
        File::ensureDirectoryExists($temp);

        $zip = new ZipArchive;

        try {
            if ($zip->open($backupPath) !== true) {
                throw new \RuntimeException('Unable to open the package backup archive.');
            }

            $zip->extractTo($temp);
            $zip->close();

            if (File::isDirectory($target)) {
                File::deleteDirectory($target);
            }

            File::moveDirectory($temp, $target);
        } finally {
            if (File::isDirectory($temp)) {
                File::deleteDirectory($temp);
            }
        }
    }

    /**
     * @return array<int, string> newest first
     */
    public function backupsFor(string $type, string $slug): array
    {
        $pattern = $this->directory().'/'.$type.'-'.$slug.'-*.zip';
        $files = glob($pattern) ?: [];

        usort($files, fn (string $a, string $b) => filemtime($b) <=> filemtime($a));

        return array_values($files);
    }

    public function latest(string $type, string $slug): ?string
    {
        return $this->backupsFor($type, $slug)[0] ?? null;
    }

    public function prune(string $type, string $slug, ?int $keep = null): void
    {
        $keep = $keep ?? (int) config('nebula.package_backup_retention', 5);
        $keep = max(1, $keep);

        foreach (array_slice($this->backupsFor($type, $slug), $keep) as $file) {
            @unlink($file);
        }
    }

    protected function packagePath(string $type, string $slug): string
    {
        if (! in_array($type, ['plugin', 'theme'], true)) {
            throw new \InvalidArgumentException('Unknown package type.');
        }

        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $slug)) {
            throw new \InvalidArgumentException('Invalid package slug.');
        }

        $folder = $type === 'theme' ? 'themes' : 'plugins';

        return base_path("{$folder}/{$slug}");
    }
}
