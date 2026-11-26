<?php

use App\Models\Category;
use App\Models\Post;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

function seo_post(array $overrides = []): Post
{
    $user = User::factory()->create();

    return Post::factory()->create(array_merge([
        'user_id' => $user->id,
        'category_id' => Category::factory()->create()->id,
        'title' => 'SEO post',
        'slug' => 'seo-post',
        'excerpt' => null,
        'content' => '<p>Body content</p>',
        'status' => 'published',
        'published_at' => now()->subHour(),
    ], $overrides));
}

it('stores SEO fields from the admin form', function () {
    $user = User::factory()->create();
    $user->assignRole('Admin');

    $category = Category::factory()->create();

    $this->actingAs($user)
        ->post(route('admin.posts.store', absolute: false), [
            'title' => 'SEO post',
            'content' => '<p>Body</p>',
            'category_id' => $category->id,
            'status' => 'draft',
            'meta_title' => 'Custom SEO title',
            'meta_description' => 'Custom SEO description',
            'canonical_url' => 'https://example.com/original-post',
            'robots' => 'noindex,follow',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $post = Post::query()->firstOrFail();

    expect($post->meta_title)->toBe('Custom SEO title')
        ->and($post->meta_description)->toBe('Custom SEO description')
        ->and($post->canonical_url)->toBe('https://example.com/original-post')
        ->and($post->robots)->toBe('noindex,follow');
});

it('renders SEO meta tags on the public post page', function () {
    seo_post([
        'meta_title' => 'Custom SEO title',
        'meta_description' => 'Custom SEO description',
        'canonical_url' => 'https://example.com/original-post',
        'robots' => 'noindex,follow',
    ]);

    $this->get(route('blog.post', 'seo-post', absolute: false))
        ->assertOk()
        ->assertSee('Custom SEO title', false)
        ->assertSee('<meta name="description" content="Custom SEO description">', false)
        ->assertSee('<meta name="robots" content="noindex,follow">', false)
        ->assertSee('<link rel="canonical" href="https://example.com/original-post">', false);
});

it('exposes SEO fields through the public API', function () {
    seo_post([
        'meta_title' => 'API SEO title',
        'meta_description' => 'API SEO description',
        'robots' => 'noindex,nofollow',
    ]);

    $this->getJson('/api/v1/posts/seo-post')
        ->assertOk()
        ->assertJsonPath('data.seo.meta_title', 'API SEO title')
        ->assertJsonPath('data.seo.meta_description', 'API SEO description')
        ->assertJsonPath('data.seo.robots', 'noindex,nofollow');
});
