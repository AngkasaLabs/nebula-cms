<?php

use App\Models\Page;
use App\Models\PageRevision;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

function page_admin(): User
{
    $user = User::factory()->create();
    $user->assignRole('Admin');

    return $user;
}

function make_page(array $overrides = []): Page
{
    return Page::create(array_merge([
        'title' => 'About us',
        'slug' => 'about-us',
        'content' => '<p>Original content</p>',
        'status' => 'published',
        'published_at' => now()->subHour(),
        'user_id' => User::factory()->create()->id,
    ], $overrides));
}

it('creates a revision before updating a page', function () {
    $page = make_page();

    $this->actingAs(page_admin())
        ->put(route('admin.pages.update', $page, absolute: false), [
            'title' => 'About us',
            'content' => '<p>Updated content</p>',
            'status' => 'published',
        ])
        ->assertSessionHasNoErrors();

    $revision = PageRevision::query()->sole();

    expect($page->fresh()->content)->toContain('Updated content')
        ->and($revision->content)->toContain('Original content')
        ->and($revision->page_id)->toBe($page->id);
});

it('restores a page revision and snapshots the current state first', function () {
    $page = make_page();
    $admin = page_admin();

    $this->actingAs($admin)->put(route('admin.pages.update', $page, absolute: false), [
        'title' => 'About us',
        'content' => '<p>Updated content</p>',
        'status' => 'published',
    ])->assertSessionHasNoErrors();

    $revision = PageRevision::query()->sole();

    $this->actingAs($admin)
        ->post(route('admin.pages.revisions.restore', ['page' => $page, 'revision' => $revision], absolute: false))
        ->assertRedirect(route('admin.pages.edit', $page, absolute: false));

    expect($page->fresh()->content)->toContain('Original content')
        ->and(PageRevision::query()->count())->toBe(2);
});

it('renders the page revision history', function () {
    $page = make_page();
    $admin = page_admin();

    $this->actingAs($admin)->put(route('admin.pages.update', $page, absolute: false), [
        'title' => 'About us',
        'content' => '<p>Updated content</p>',
        'status' => 'published',
    ]);

    $this->actingAs($admin)
        ->get(route('admin.pages.revisions', $page, absolute: false))
        ->assertOk()
        ->assertInertia(fn ($inertia) => $inertia
            ->component('Admin/Pages/Revisions')
            ->has('revisions', 1)
            ->where('page.id', $page->id));
});

it('hides scheduled pages from the public site until they are due', function () {
    $page = make_page([
        'slug' => 'scheduled-page',
        'status' => 'scheduled',
        'published_at' => now()->addHour(),
    ]);

    $this->get(route('page', ['slug' => $page->slug], absolute: false))->assertNotFound();

    $this->travel(2)->hours();
    $this->artisan('posts:publish-scheduled')->assertSuccessful();

    expect($page->fresh()->status)->toBe('published');

    $this->get(route('page', ['slug' => $page->slug], absolute: false))->assertOk();
});

it('requires a future date when scheduling a page', function () {
    $admin = page_admin();

    $this->actingAs($admin)
        ->post(route('admin.pages.store', absolute: false), [
            'title' => 'Future page',
            'content' => '<p>Content</p>',
            'status' => 'scheduled',
            'published_at' => now()->subDay()->toDateTimeString(),
        ])
        ->assertSessionHasErrors('published_at');

    expect(Page::query()->where('title', 'Future page')->exists())->toBeFalse();
});
