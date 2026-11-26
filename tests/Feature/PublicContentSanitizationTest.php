<?php

use App\Models\Category;
use App\Models\Page;
use App\Models\Post;
use App\Models\User;

it('sanitises stored post content when rendering publicly', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create();

    $post = Post::factory()->create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'title' => 'Rendered post',
        'slug' => 'rendered-post',
        'content' => '<p>visible</p><script>alert(1)</script><img src="x" onerror="alert(2)">',
        'status' => 'published',
        'published_at' => now()->subHour(),
    ]);

    $response = $this->get(route('blog.post', $post->slug, absolute: false));

    $response->assertOk()
        ->assertSee('Rendered post', false)
        ->assertSee('visible', false)
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertDontSee('onerror', false);
});

it('sanitises stored page content when rendering publicly', function () {
    $user = User::factory()->create();

    Page::create([
        'user_id' => $user->id,
        'title' => 'Rendered page',
        'slug' => 'rendered-page',
        'content' => '<p>page body</p><script>alert(1)</script><iframe src="https://evil.test"></iframe>',
        'status' => 'published',
    ]);

    $this->get(route('page', 'rendered-page', absolute: false))
        ->assertOk()
        ->assertSee('page body', false)
        ->assertDontSee('<script>alert(1)</script>', false)
        ->assertDontSee('<iframe', false);
});
