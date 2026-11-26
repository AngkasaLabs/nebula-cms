<?php

use App\Models\Category;
use App\Models\Post;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

function settings_user(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

it('lets an admin scan stored content', function () {
    $user = settings_user('Admin');
    $category = Category::factory()->create();

    Post::factory()->create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'content' => '<p>x</p><script>alert(1)</script>',
    ]);

    $this->actingAs($user)
        ->post(route('admin.settings.content.scan', absolute: false))
        ->assertRedirect(route('admin.settings.security', absolute: false))
        ->assertSessionHas('contentSafetyReport');
});

it('lets an admin sanitise stored content', function () {
    $user = settings_user('Admin');
    $category = Category::factory()->create();

    $post = Post::factory()->create([
        'user_id' => $user->id,
        'category_id' => $category->id,
        'content' => '<p>x</p><script>alert(1)</script>',
    ]);

    $this->actingAs($user)
        ->post(route('admin.settings.content.apply', absolute: false))
        ->assertRedirect(route('admin.settings.security', absolute: false))
        ->assertSessionHas('contentSafetyApplied', true);

    expect($post->fresh()->content)->not->toContain('<script');
});

it('forbids editors from running the content scan', function () {
    $this->actingAs(settings_user('Editor'))
        ->post(route('admin.settings.content.scan', absolute: false))
        ->assertForbidden();
});
