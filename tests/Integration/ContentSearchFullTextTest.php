<?php

use App\Models\Category;
use App\Models\Post;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\TestCase;

/**
 * Full-text search integration tests.
 *
 * InnoDB FULLTEXT indexes only see committed rows, while RefreshDatabase wraps
 * every test in a transaction, so these tests use DatabaseTruncation and run
 * from the MySQL job via phpunit.integration.xml.
 */
uses(TestCase::class, DatabaseTruncation::class);

beforeEach(function () {
    $this->withoutVite();
});

it('matches posts through the full-text index', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create();

    Post::factory()->create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'title' => 'API Search Match',
        'slug' => 'api-search-match',
        'status' => 'published',
        'published_at' => now()->subHour(),
    ]);

    $this->getJson('/api/v1/posts?search=API+Search')
        ->assertOk()
        ->assertJsonPath('data.0.slug', 'api-search-match');
});

it('keeps the public blog search working with the full-text index', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create();

    Post::factory()->create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'title' => 'Alpha UniqueBlogFind 123',
        'slug' => 'alpha-post',
        'status' => 'published',
        'published_at' => now()->subHour(),
    ]);

    $this->get(route('blog', ['search' => 'UniqueBlogFind']))
        ->assertOk()
        ->assertSee('Alpha UniqueBlogFind 123', false);
});
