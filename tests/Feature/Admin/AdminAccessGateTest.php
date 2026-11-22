<?php

use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

it('blocks accounts without the access admin permission from the admin panel', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('view dashboard');

    $this->actingAs($user)
        ->get(route('admin.dashboard', absolute: false))
        ->assertForbidden();
});

it('allows staff roles with access admin into the admin panel', function (string $role) {
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)
        ->get(route('admin.dashboard', absolute: false))
        ->assertOk();
})->with(['Super Admin', 'Admin', 'Editor', 'Author']);

it('keeps developer-style roles out of the admin panel', function () {
    Role::firstOrCreate(['name' => 'Developer', 'guard_name' => 'web']);

    $user = User::factory()->create();
    $user->assignRole('Developer');

    $this->actingAs($user)
        ->get(route('admin.dashboard', absolute: false))
        ->assertForbidden();
});

it('does not share admin navigation or update data with members', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get(route('verification.notice', absolute: false))
        ->assertInertia(fn ($page) => $page
            ->where('adminNavigation', [])
            ->where('updateAvailable', null)
        );
});
