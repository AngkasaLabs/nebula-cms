<?php

use App\Models\Category;
use App\Models\Post;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

it('reports unsafe content without changing it during a dry run', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create();

    $post = Post::factory()->create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'title' => 'Unsafe post',
        'content' => '<p>safe</p><script>alert(1)</script>',
    ]);

    $this->artisan('content:sanitize')
        ->expectsOutputToContain('Scanned 1 item(s).')
        ->expectsOutputToContain('Dry run only')
        ->assertSuccessful();

    expect($post->fresh()->content)->toContain('<script');
});

it('writes sanitised content when --apply is passed', function () {
    $user = User::factory()->create();
    $category = Category::factory()->create();

    $post = Post::factory()->create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'title' => 'Unsafe post',
        'content' => '<p>safe</p><script>alert(1)</script>',
    ]);

    $this->artisan('content:sanitize', ['--apply' => true])
        ->expectsOutputToContain('Sanitised content written back')
        ->assertSuccessful();

    $content = $post->fresh()->content;

    expect($content)->toContain('<p>safe</p>')
        ->not->toContain('<script');
});
