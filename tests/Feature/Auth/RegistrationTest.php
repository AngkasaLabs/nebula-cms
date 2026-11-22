<?php

use App\Models\Setting;
use App\Models\User;
use Spatie\Permission\Models\Role;

test('registration is disabled by default', function () {
    $this->get('/register')->assertForbidden();
});

test('registration screen can be rendered when enabled', function () {
    Setting::set('registration_enabled', true);

    $response = $this->get('/register');

    $response->assertStatus(200);
});

test('new users can register and land on the member home', function () {
    Setting::set('registration_enabled', true);

    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $this->assertAuthenticated();
    $response->assertRedirect('/');
});

test('registration assigns the configured default role', function () {
    Setting::set('registration_enabled', true);
    Role::firstOrCreate(['name' => 'Member', 'guard_name' => 'web']);
    Setting::set('registration_default_role', 'Member');

    $this->post('/register', [
        'name' => 'Member User',
        'email' => 'member@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::where('email', 'member@example.com')->first();

    expect($user)->not->toBeNull();
    expect($user->hasRole('Member'))->toBeTrue();
    expect($user->can('access admin'))->toBeFalse();
});
