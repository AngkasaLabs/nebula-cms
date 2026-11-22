<?php

namespace App\Support;

use App\Models\Setting;
use App\Models\User;
use Spatie\Permission\Models\Role;

/**
 * Centralises where a freshly authenticated user should land.
 *
 * Accounts with `access admin` keep the historical behaviour (admin
 * dashboard). Everyone else is sent to a configurable member landing page so
 * the admin panel stays closed to them.
 */
class AuthRedirect
{
    public const ADMIN_PERMISSION = 'access admin';

    public static function afterAuth(User $user): string
    {
        if (static::canAccessAdmin($user)) {
            return route('admin.dashboard', absolute: false);
        }

        return static::memberHome();
    }

    public static function canAccessAdmin(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        try {
            return $user->can(self::ADMIN_PERMISSION);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Drop a pending "intended" admin URL for members so they are not bounced
     * into a 403 immediately after signing in.
     */
    public static function forgetUnsafeIntended(User $user): void
    {
        if (static::canAccessAdmin($user)) {
            return;
        }

        $intended = session('url.intended');

        if (! is_string($intended)) {
            return;
        }

        $path = parse_url($intended, PHP_URL_PATH) ?: '';

        if ($path === '/admin' || str_starts_with($path, '/admin/')) {
            session()->forget('url.intended');
        }
    }

    /**
     * Only same-origin paths are allowed to prevent open redirects.
     */
    public static function memberHome(): string
    {
        try {
            $target = Setting::get('member_redirect', '/');
        } catch (\Throwable) {
            return '/';
        }

        if (! is_string($target) || $target === '') {
            return '/';
        }

        if (! str_starts_with($target, '/') || str_starts_with($target, '//')) {
            return '/';
        }

        return $target;
    }

    public static function registrationEnabled(): bool
    {
        try {
            return filter_var(Setting::get('registration_enabled', false), FILTER_VALIDATE_BOOLEAN);
        } catch (\Throwable) {
            return false;
        }
    }

    public static function defaultRegistrationRole(): ?string
    {
        try {
            $role = Setting::get('registration_default_role');
        } catch (\Throwable) {
            return null;
        }

        if (! is_string($role) || $role === '') {
            return null;
        }

        return Role::query()
            ->where('name', $role)
            ->where('guard_name', 'web')
            ->exists()
            ? $role
            : null;
    }
}
