<?php

use App\Models\Setting;
use App\Models\User;
use App\Support\MailSettings;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

function mail_admin(): User
{
    $user = User::factory()->create();
    $user->assignRole('Admin');

    return $user;
}

it('renders the email settings page for administrators', function () {
    $this->actingAs(mail_admin())
        ->get(route('admin.settings.email', absolute: false))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Settings/Email')
            ->where('source', 'env')
            ->has('settings.host')
            ->where('settings.has_password', false));
});

it('forbids members from opening the email settings page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('admin.settings.email', absolute: false))
        ->assertForbidden();
});

it('stores smtp settings with an encrypted password', function () {
    $this->actingAs(mail_admin())
        ->put(route('admin.settings.email.update', absolute: false), [
            'host' => 'smtp.example.com',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'postmaster@example.com',
            'password' => 'super-secret',
            'from_address' => 'hello@example.com',
            'from_name' => 'Example Site',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $stored = MailSettings::all();
    $raw = (string) DB::table('settings')->where('key', MailSettings::KEY)->value('value');

    expect($stored['host'])->toBe('smtp.example.com')
        ->and($stored['encryption'])->toBe('tls')
        ->and($stored['password'])->toBe('super-secret')
        ->and($raw)->not->toContain('super-secret');
});

it('keeps the stored password when the form leaves it blank', function () {
    MailSettings::save([
        'host' => 'smtp.example.com',
        'port' => 587,
        'password' => 'first-secret',
        'from_address' => 'hello@example.com',
    ]);

    $this->actingAs(mail_admin())
        ->put(route('admin.settings.email.update', absolute: false), [
            'host' => 'smtp.example.com',
            'port' => 465,
            'encryption' => 'ssl',
            'username' => '',
            'password' => '',
            'from_address' => 'hello@example.com',
            'from_name' => 'Example Site',
        ])
        ->assertSessionHasNoErrors();

    $stored = MailSettings::all();

    expect($stored['password'])->toBe('first-secret')
        ->and($stored['port'])->toBe(465);
});

it('applies stored settings over the environment mail config', function () {
    MailSettings::save([
        'host' => 'smtp.example.com',
        'port' => 2525,
        'encryption' => 'tls',
        'username' => 'user',
        'password' => 'secret',
        'from_address' => 'hello@example.com',
        'from_name' => 'Example Site',
    ]);

    MailSettings::applyToConfig();

    expect(config('mail.default'))->toBe('smtp')
        ->and(config('mail.mailers.smtp.host'))->toBe('smtp.example.com')
        ->and(config('mail.mailers.smtp.port'))->toBe(2525)
        ->and(config('mail.from.address'))->toBe('hello@example.com');
});

it('sends a test email through the configured mailer', function () {
    config([
        'mail.default' => 'log',
        'mail.from.address' => 'hello@example.com',
    ]);

    $this->actingAs(mail_admin())
        ->post(route('admin.settings.email.test', absolute: false), [
            'email' => 'recipient@example.com',
        ])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('success');
});

it('resets stored settings back to the environment configuration', function () {
    MailSettings::save([
        'host' => 'smtp.example.com',
        'from_address' => 'hello@example.com',
    ]);

    $this->actingAs(mail_admin())
        ->delete(route('admin.settings.email.reset', absolute: false))
        ->assertSessionHas('success');

    expect(MailSettings::all())->toBe([])
        ->and(Setting::query()->where('key', MailSettings::KEY)->exists())->toBeFalse();
});
