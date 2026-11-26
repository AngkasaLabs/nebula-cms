<?php

namespace App\Console\Commands;

use App\Models\Page;
use App\Models\Post;
use App\Support\HtmlSanitizer;
use Illuminate\Console\Command;

class SanitizeContent extends Command
{
    protected $signature = 'content:sanitize
                            {--apply : Write the sanitised HTML back to the database}
                            {--type=all : Scan posts, pages, or all}';

    protected $description = 'Find (and optionally remove) unsafe HTML stored in post and page content';

    public function handle(HtmlSanitizer $sanitizer): int
    {
        $type = strtolower((string) $this->option('type'));

        if (! in_array($type, ['all', 'posts', 'pages'], true)) {
            $this->error('--type must be one of: all, posts, pages');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');

        $scanned = 0;
        $changed = 0;
        $samples = [];

        $scan = function (string $label, $query) use ($sanitizer, $apply, &$scanned, &$changed, &$samples): void {
            $query->select('id', 'title', 'content')
                ->chunkById(100, function ($rows) use ($label, $sanitizer, $apply, &$scanned, &$changed, &$samples): void {
                    foreach ($rows as $row) {
                        $scanned++;

                        $clean = $sanitizer->sanitize($row->content);

                        if ($clean === $row->content) {
                            continue;
                        }

                        $changed++;

                        if (count($samples) < 5) {
                            $samples[] = "{$label} #{$row->id}: {$row->title}";
                        }

                        if ($apply) {
                            $row->forceFill(['content' => $clean])->saveQuietly();
                        }
                    }
                });
        };

        if (in_array($type, ['all', 'posts'], true)) {
            $scan('Post', Post::query());
        }

        if (in_array($type, ['all', 'pages'], true)) {
            $scan('Page', Page::query());
        }

        $this->info("Scanned {$scanned} item(s).");

        if ($changed === 0) {
            $this->info('No unsafe markup found.');

            return self::SUCCESS;
        }

        $this->warn("{$changed} item(s) contain markup that would be sanitised:");
        foreach ($samples as $sample) {
            $this->line("  - {$sample}");
        }

        if ($apply) {
            $this->info('Sanitised content written back to the database.');

            return self::SUCCESS;
        }

        $this->comment('Dry run only. Re-run with --apply to write the changes.');

        return self::SUCCESS;
    }
}
