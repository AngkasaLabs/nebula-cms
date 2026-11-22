<?php

namespace App\Services;

use App\Models\Plugin;
use App\Models\PluginMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Runs migrations shipped inside a plugin's `database/migrations` folder.
 *
 * Records live in a dedicated `plugin_migrations` table (keyed by plugin) so
 * plugin migrations never mix with the core `migrations` table and can be
 * rolled back when the plugin is uninstalled.
 */
class PluginMigrator
{
    public function migrationsPath(Plugin $plugin): string
    {
        return base_path("plugins/{$plugin->folder_name}/database/migrations");
    }

    /**
     * @return array<string, string> migration name => absolute path
     */
    public function available(Plugin $plugin): array
    {
        $path = $this->migrationsPath($plugin);

        if (! File::isDirectory($path)) {
            return [];
        }

        $files = File::glob($path.'/*.php') ?: [];
        $migrations = [];

        foreach ($files as $file) {
            $migrations[basename($file, '.php')] = $file;
        }

        ksort($migrations);

        return $migrations;
    }

    /**
     * @return array<string, string>
     */
    public function pending(Plugin $plugin): array
    {
        $ran = PluginMigration::query()
            ->where('plugin', $plugin->folder_name)
            ->pluck('migration')
            ->all();

        return array_diff_key($this->available($plugin), array_flip($ran));
    }

    /**
     * @return array<int, string> migration names that ran
     */
    public function migrate(Plugin $plugin): array
    {
        $pending = $this->pending($plugin);

        if ($pending === []) {
            return [];
        }

        $batch = (int) PluginMigration::query()
            ->where('plugin', $plugin->folder_name)
            ->max('batch') + 1;

        $ran = [];

        foreach ($pending as $name => $file) {
            $this->resolve($file)->up();

            PluginMigration::create([
                'plugin' => $plugin->folder_name,
                'migration' => $name,
                'batch' => $batch,
            ]);

            $ran[] = $name;
        }

        return $ran;
    }

    /**
     * @return array<int, string> migration names that were rolled back
     */
    public function rollback(Plugin $plugin): array
    {
        $records = PluginMigration::query()
            ->where('plugin', $plugin->folder_name)
            ->orderByDesc('batch')
            ->orderByDesc('id')
            ->get();

        $rolledBack = [];

        foreach ($records as $record) {
            $file = $this->migrationsPath($plugin).'/'.$record->migration.'.php';

            if (File::exists($file)) {
                $this->resolve($file)->down();
                $rolledBack[] = $record->migration;
            }

            $record->delete();
        }

        return $rolledBack;
    }

    public function resolve(string $file): Migration
    {
        $class = $this->classFromFile($file);

        if (class_exists($class)) {
            $reflection = new \ReflectionClass($class);

            if ($reflection->getFileName() && realpath($reflection->getFileName()) === realpath($file)) {
                return new $class;
            }
        }

        $migration = require $file;

        if ($migration instanceof Migration) {
            return $migration;
        }

        if (class_exists($class)) {
            return new $class;
        }

        throw new RuntimeException("Migration file [{$file}] did not return a Migration instance.");
    }

    protected function classFromFile(string $file): string
    {
        $name = basename($file, '.php');
        $parts = explode('_', $name);

        if (count($parts) > 4 && ctype_digit($parts[0])) {
            $name = implode('_', array_slice($parts, 4));
        }

        return Str::studly($name);
    }
}
