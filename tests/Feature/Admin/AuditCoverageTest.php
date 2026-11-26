<?php

use App\Models\Media;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('Admin');
});

it('records settings updates', function () {
    $this->actingAs($this->admin)
        ->put(route('admin.settings.update', 'general', absolute: false), [
            'settings' => ['store_url' => 'https://example.com/store'],
        ])
        ->assertRedirect();

    $activity = Activity::query()->where('description', 'settings_updated')->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->causer_id)->toBe($this->admin->id)
        ->and($activity->properties['keys'])->toContain('store_url');
});

it('records user creation', function () {
    $this->actingAs($this->admin)
        ->post(route('admin.users.store', absolute: false), [
            'name' => 'New Editor',
            'email' => 'editor@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'roles' => ['Editor'],
        ])
        ->assertRedirect();

    $activity = Activity::query()->where('description', 'user_created')->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->properties['roles'])->toContain('Editor');
});

it('records role creation', function () {
    $permission = Permission::where('name', 'view dashboard')->firstOrFail();

    $this->actingAs($this->admin)
        ->post(route('admin.roles.store', absolute: false), [
            'name' => 'Support',
            'permissions' => [$permission->id],
        ])
        ->assertRedirect();

    $activity = Activity::query()->where('description', 'role_created')->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->properties['permissions'])->toContain($permission->id);
});

it('records media deletion', function () {
    $media = Media::create([
        'user_id' => $this->admin->id,
        'name' => 'cover.jpg',
        'file_name' => 'cover.jpg',
        'mime_type' => 'image/jpeg',
        'path' => 'media/cover.jpg',
        'disk' => 'public',
        'file_hash' => 'hash-'.uniqid(),
        'size' => 1024,
    ]);

    $this->actingAs($this->admin)
        ->delete(route('admin.media.destroy', $media->id, absolute: false))
        ->assertRedirect();

    $activity = Activity::query()->where('description', 'media_deleted')->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->properties['id'])->toBe($media->id);
});
