<?php

namespace App\Console\Commands;

use App\Services\ContentSafetyAuditor;
use Illuminate\Console\Command;

class SanitizeContent extends Command
{
    protected $signature = 'content:sanitize
                            {--apply : Write the sanitised HTML back to the database}
                            {--type=all : Scan posts, pages, or all}
                            {--limit=2000 : Maximum number of items to scan in one run}';

    protected $description = 'Find (and optionally remove) unsafe HTML stored in post and page content';

    public function handle(ContentSafetyAuditor $auditor): int
    {
        $type = strtolower((string) $this->option('type'));

        if (! in_array($type, ['all', 'posts', 'pages'], true)) {
            $this->error('--type must be one of: all, posts, pages');

            return self::FAILURE;
        }

        $types = match ($type) {
            'posts' => ['post'],
            'pages' => ['page'],
            default => ['post', 'page'],
        };

        $apply = (bool) $this->option('apply');
        $limit = max(1, (int) $this->option('limit'));

        $report = $apply
            ? $auditor->apply($limit, $types)
            : $auditor->scan($limit, $types);

        $this->info("Scanned {$report['scanned']} item(s).");

        if ($report['affected'] === 0) {
            $this->info('No unsafe markup found.');

            return self::SUCCESS;
        }

        $this->warn("{$report['affected']} item(s) contain markup that would be sanitised:");
        foreach ($report['samples'] as $sample) {
            $this->line("  - {$sample['label']} #{$sample['id']}: {$sample['title']}");
        }

        if ($report['truncated']) {
            $this->comment("Stopped at the {$limit}-item limit. Run the command again to continue.");
        }

        if ($apply) {
            $this->info('Sanitised content written back to the database.');

            return self::SUCCESS;
        }

        $this->comment('Dry run only. Re-run with --apply to write the changes.');

        return self::SUCCESS;
    }
}
