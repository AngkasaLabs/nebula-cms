<?php

namespace App\Console\Commands;

use App\Services\BackupService;
use Illuminate\Console\Command;

class RunBackup extends Command
{
    protected $signature = 'backup:run {--keep= : Number of backup archives to keep}';

    protected $description = 'Create a full-site backup (files and database)';

    public function handle(BackupService $backups): int
    {
        try {
            $backup = $backups->create('scheduled');
        } catch (\Throwable $e) {
            $this->error('Backup failed: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('keep') !== null) {
            $backups->prune((int) $this->option('keep'));
        }

        $this->info('Backup created: '.$backup['filename'].' ('.number_format($backup['size'] / 1048576, 1).' MB)');

        return self::SUCCESS;
    }
}
