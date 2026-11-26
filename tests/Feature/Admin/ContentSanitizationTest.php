<?php

use App\Models\Category;
use App\Models\Page;
use App\Models\Post;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

function content_user(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('only gives admin roles the unfiltered_html permission', function () {
    expect(content_user('Admin')->can('unfiltered_html'))->toBeTrue()
        ->and(content_user('Super Admin')->can('unfiltered_html'))->toBeTrue()
        ->and(content_user('Editor')->can('unfiltered_html'))->toBeFalse()
        ->and(content_user('Author')->can('unfiltered_html'))->toBeFalse();
});

it('sanitises post content from authors', function () {
    $user = content_user('Author');
    $category = Category::factory()->create();

    $this->actingAs($user)
        ->post(route('admin.posts.store', absolute: false), [
            'title' => 'Author post',
            'content' => '<p>safe</p><script>alert(1)</script>',
            'category_id' => $category->id,
            'status' => 'draft',
        ])
        ->assertRedirect();

    $content = Post::query()->firstOrFail()->content;

    expect($content)->toContain('<p>safe</p>')
        ->not->toContain('<script');
});

it('stores unfiltered post content for admins', function () {
    $user = content_user('Admin');
    $category = Category::factory()->create();

    $this->actingAs($user)
        ->post(route('admin.posts.store', absolute: false), [
            'title' => 'Admin post',
            'content' => '<p>safe</p><script>alert(1)</script>',
            'category_id' => $category->id,
            'status' => 'draft',
        ])
        ->assertRedirect();

    expect(Post::query()->firstOrFail()->content)->toContain('<script');
});

it('sanitises page content from editors', function () {
    $user = content_user('Editor');

    $this->actingAs($user)
        ->post(route('admin.pages.store', absolute: false), [
            'title' => 'Editor page',
            'content' => '<p>safe</p><img src="x" onerror="alert(1)">',
            'status' => 'draft',
        ])
        ->assertRedirect();

    $content = Page::query()->firstOrFail()->content;

    expect($content)->toContain('<p>safe</p>')
        ->not->toContain('onerror');
});

it('sanitises a restored revision for users without unfiltered_html', function () {
    $editor = content_user('Editor');
    $category = Category::factory()->create();

    $post = Post::factory()->create([
        'user_id' => $editor->id,
        'category_id' => $category->id,
        'content' => '<p>clean</p>',
    ]);

    $this->actingAs($editor);
    $post->createRevision();

    $revision = $post->revisions()->firstOrFail();
    $revision->forceFill(['content' => '<p>restored</p><script>alert(1)</script>'])->save();

    $this->actingAs($editor)
        ->post(route('admin.posts.revisions.restore', [$post, $revision], absolute: false))
        ->assertRedirect();

    $content = $post->fresh()->content;

    expect($content)->toContain('<p>restored</p>')
        ->not->toContain('<script');
});
