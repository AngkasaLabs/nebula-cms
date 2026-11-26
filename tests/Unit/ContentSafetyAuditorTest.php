<?php

use App\Models\Category;
use App\Models\Page;
use App\Models\Post;
use App\Models\User;
use App\Services\ContentSafetyAuditor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->category = Category::factory()->create();
});

it('reports unsafe content without changing it during a scan', function () {
    $post = Post::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $this->category->id,
        'title' => 'Unsafe post',
        'content' => '<p>safe</p><script>alert(1)</script>',
    ]);

    Post::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $this->category->id,
        'title' => 'Clean post',
        'content' => '<p>clean</p>',
    ]);

    Page::create([
        'user_id' => $this->user->id,
        'title' => 'Unsafe page',
        'slug' => 'unsafe-page',
        'content' => '<p>page</p><iframe src="https://evil.test"></iframe>',
        'status' => 'published',
    ]);

    $report = app(ContentSafetyAuditor::class)->scan();

    expect($report['scanned'])->toBe(3)
        ->and($report['affected'])->toBe(2)
        ->and($report['truncated'])->toBeFalse()
        ->and($report['samples'])->toHaveCount(2);

    expect($post->fresh()->content)->toContain('<script');
});

it('sanitises stored content when applied', function () {
    $post = Post::factory()->create([
        'user_id' => $this->user->id,
        'category_id' => $this->category->id,
        'content' => '<p>safe</p><script>alert(1)</script>',
    ]);

    $report = app(ContentSafetyAuditor::class)->apply();

    expect($report['affected'])->toBe(1)
        ->and($post->fresh()->content)->toContain('<p>safe</p>')
        ->and($post->fresh()->content)->not->toContain('<script');
});

it('limits scans to the requested type and batch size', function () {
    Post::factory()->count(3)->create([
        'user_id' => $this->user->id,
        'category_id' => $this->category->id,
        'content' => '<p>x</p><script>alert(1)</script>',
    ]);

    $pagesOnly = app(ContentSafetyAuditor::class)->scan(10, ['page']);
    expect($pagesOnly['scanned'])->toBe(0);

    $limited = app(ContentSafetyAuditor::class)->scan(2, ['post']);
    expect($limited['scanned'])->toBe(2)
        ->and($limited['truncated'])->toBeTrue();
});
