<?php

use App\Models\User;
use App\Support\SecuritySettings;
use Database\Seeders\RoleAndPermissionSeeder;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

it('sends a report-only content security policy by default', function () {
    $response = $this->get('/login');

    $header = (string) $response->headers->get('Content-Security-Policy-Report-Only');

    expect($header)->toContain('script-src')
        ->and($header)->toContain("'nonce-")
        ->and($header)->toContain('https://cdn.jsdelivr.net')
        ->and($response->headers->get('Content-Security-Policy'))->toBeNull();
});

it('enforces the policy after an administrator enables it', function () {
    $user = User::factory()->create();
    $user->assignRole('Admin');

    $this->actingAs($user)
        ->post(route('admin.settings.security.csp', absolute: false), ['enforce' => true])
        ->assertSessionHas('success');

    expect(SecuritySettings::cspReportOnly())->toBeFalse();

    $response = $this->actingAs($user)->get('/login');
    $header = (string) $response->headers->get('Content-Security-Policy');

    expect($header)->toContain('script-src')
        ->and($header)->toContain("'nonce-")
        ->and($response->headers->get('Content-Security-Policy-Report-Only'))->toBeNull();
});

it('returns to report-only mode when disabled', function () {
    SecuritySettings::setCspReportOnly(false);

    $user = User::factory()->create();
    $user->assignRole('Admin');

    $this->actingAs($user)
        ->post(route('admin.settings.security.csp', absolute: false), ['enforce' => false])
        ->assertSessionHas('success');

    expect(SecuritySettings::cspReportOnly())->toBeTrue();

    $response = $this->actingAs($user)->get('/login');

    expect($response->headers->get('Content-Security-Policy-Report-Only'))->not->toBeNull()
        ->and($response->headers->get('Content-Security-Policy'))->toBeNull();
});

it('forbids members from changing the csp mode', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('admin.settings.security.csp', absolute: false), ['enforce' => true])
        ->assertForbidden();
});
