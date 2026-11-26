<?php

namespace App\Console\Commands;

use App\Models\Page;
use App\Models\Post;
use Illuminate\Console\Command;

class PublishScheduledPosts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'posts:publish-scheduled';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Publish scheduled posts and pages that are due';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $posts = Post::where('status', 'scheduled')
            ->where('published_at', '<=', now())
            ->get();

        $pages = Page::where('status', 'scheduled')
            ->where('published_at', '<=', now())
            ->get();

        if ($posts->isEmpty() && $pages->isEmpty()) {
            $this->info('No scheduled content to publish.');

            return 0;
        }

        $count = 0;
        foreach ($posts as $post) {
            $post->update(['status' => 'published']);
            $this->line("Published post: {$post->title}");
            $count++;
        }

        foreach ($pages as $page) {
            $page->update(['status' => 'published']);
            $this->line("Published page: {$page->title}");
            $count++;
        }

        $this->info("Published {$count} scheduled item(s).");

        return 0;
    }
}
