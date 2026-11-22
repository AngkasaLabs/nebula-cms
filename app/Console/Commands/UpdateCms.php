<?php

namespace App\Console\Commands;

use App\Services\UpdateService;
use Illuminate\Console\Command;

class UpdateCms extends Command
{
    protected $signature = 'cms:update
                            {--force : Apply the latest release even when already up to date}
                            {--no-backup : Skip the pre-update backup (not recommended)}
                            {--yes : Skip the confirmation prompt}';

    protected $description = 'Download and apply a NebulaCMS update from GitHub Releases';

    public function handle(UpdateService $service): int
    {
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        @ignore_user_abort(true);

        $check = $service->checkForUpdate();

        if (isset($check['error'])) {
            $this->error($check['error']);

            return self::FAILURE;
        }

        $current = (string) ($check['current'] ?? config('nebula.version'));
        $latest = (string) ($check['latest'] ?? 'unknown');

        if (($check['available'] ?? false) !== true && ! $this->option('force')) {
            $this->info("NebulaCMS is already up to date (v{$current}).");

            return self::SUCCESS;
        }

        $downloadUrl = $check['download_url'] ?? null;

        if (! is_string($downloadUrl) || $downloadUrl === '') {
            $this->error('No release ZIP asset was found for the latest release.');

            return self::FAILURE;
        }

        $this->info("Updating NebulaCMS v{$current} → v{$latest}");

        if (! $this->option('yes') && ! $this->confirm('Continue? A pre-update backup is created first.', true)) {
            $this->warn('Update cancelled.');

            return self::SUCCESS;
        }

        if (! $this->option('no-backup')) {
            try {
                $backup = $service->createBackup();
                $this->info("Backup created: {$backup}");
            } catch (\Throwable $e) {
                $this->error('Backup failed: '.$e->getMessage());

                return self::FAILURE;
            }
        } else {
            $this->warn('Skipping the pre-update backup as requested.');
        }

        $result = $service->applyUpdate($downloadUrl);

        if (($result['success'] ?? false) !== true) {
            $this->error($result['message'] ?? 'Update failed.');

            return self::FAILURE;
        }

        $service->forgetCachedUpdateAvailability();

        $this->info(sprintf(
            '%s (from %s to %s)',
            $result['message'] ?? 'Update applied successfully.',
            $result['from'] ?? $current,
            $result['to'] ?? $latest,
        ));

        $this->comment('If you use plugins or route caching, run: php artisan route:clear');

        return self::SUCCESS;
    }
}
