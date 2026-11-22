<?php

namespace App\Console\Commands;

use App\Models\Plugin;
use App\Services\PluginMigrator;
use Illuminate\Console\Command;

class MigratePlugins extends Command
{
    protected $signature = 'plugin:migrate
                            {slug? : Plugin slug or folder name (defaults to every installed plugin)}
                            {--rollback : Roll back the last plugin migration batch}';

    protected $description = 'Run or roll back database migrations shipped inside plugins';

    public function handle(PluginMigrator $migrator): int
    {
        $slug = $this->argument('slug');

        $plugins = $slug
            ? Plugin::query()->where('slug', $slug)->orWhere('folder_name', $slug)->get()
            : Plugin::query()->orderBy('name')->get();

        if ($plugins->isEmpty()) {
            $this->error($slug ? "Plugin [{$slug}] not found." : 'No plugins installed.');

            return self::FAILURE;
        }

        $failed = false;

        foreach ($plugins as $plugin) {
            try {
                if ($this->option('rollback')) {
                    $rolledBack = $migrator->rollback($plugin);
                    $this->info(sprintf(
                        '%s: rolled back %d migration(s).',
                        $plugin->slug,
                        count($rolledBack)
                    ));
                } else {
                    $ran = $migrator->migrate($plugin);
                    $this->info(sprintf(
                        '%s: ran %d migration(s).',
                        $plugin->slug,
                        count($ran)
                    ));
                }
            } catch (\Throwable $e) {
                $failed = true;
                $this->error("{$plugin->slug}: {$e->getMessage()}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
