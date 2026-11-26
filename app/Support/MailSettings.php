<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Stores outbound mail settings in the database instead of requiring `.env`
 * edits.
 *
 * The whole blob is encrypted because it contains the SMTP password and the
 * core `settings` table stores values as plain JSON. Values are merged over
 * `config/mail.php`, so installations that never open the page keep using
 * their environment configuration.
 */
class MailSettings
{
    public const KEY = 'mail_config';

    public const GROUP = 'mail';

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        try {
            $raw = Setting::query()->where('key', self::KEY)->first()?->value;
        } catch (Throwable) {
            // The installer and fresh migrations run before the table exists.
            return [];
        }

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        try {
            $decoded = json_decode(Crypt::decryptString($raw), true);
        } catch (Throwable $e) {
            Log::warning('NebulaCMS: unable to decrypt mail settings.', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultsFromConfig(): array
    {
        return [
            'host' => (string) config('mail.mailers.smtp.host', ''),
            'port' => (int) config('mail.mailers.smtp.port', 587),
            'encryption' => (string) (config('mail.mailers.smtp.encryption') ?? ''),
            'username' => (string) (config('mail.mailers.smtp.username') ?? ''),
            'from_address' => (string) config('mail.from.address', ''),
            'from_name' => (string) config('mail.from.name', ''),
        ];
    }

    /**
     * Values shown in the admin form: saved settings first, environment values
     * as a fallback so the page is never empty on a fresh install.
     *
     * @return array<string, mixed>
     */
    public static function forDisplay(): array
    {
        $saved = self::all();

        return array_merge(self::defaultsFromConfig(), array_intersect_key($saved, self::defaultsFromConfig()));
    }

    public static function configured(): bool
    {
        $settings = self::all();

        return ($settings['host'] ?? '') !== '' && ($settings['from_address'] ?? '') !== '';
    }

    public static function hasPassword(): bool
    {
        return (string) (self::all()['password'] ?? '') !== '';
    }

    /**
     * Merge stored settings into the runtime mail configuration.
     *
     * Providers run after the cached config is loaded, so this also works on
     * installations that use `config:cache`.
     */
    public static function applyToConfig(): bool
    {
        $settings = self::all();

        if (($settings['host'] ?? '') === '') {
            return false;
        }

        $encryption = $settings['encryption'] ?? null;

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => $settings['host'],
            'mail.mailers.smtp.port' => (int) ($settings['port'] ?? 587),
            'mail.mailers.smtp.encryption' => $encryption === '' ? null : $encryption,
            'mail.mailers.smtp.username' => ($settings['username'] ?? '') === '' ? null : $settings['username'],
            'mail.mailers.smtp.password' => ($settings['password'] ?? '') === '' ? null : $settings['password'],
            'mail.from.address' => $settings['from_address'] ?? config('mail.from.address'),
            'mail.from.name' => $settings['from_name'] ?? config('mail.from.name'),
        ]);

        return true;
    }

    /**
     * Persist settings. An empty password keeps the stored one, so the form
     * never has to send the secret back to the browser.
     *
     * @param  array<string, mixed>  $values
     */
    public static function save(array $values): void
    {
        $current = self::all();

        if (array_key_exists('password', $values)
            && ($values['password'] === null || trim((string) $values['password']) === '')) {
            unset($values['password']);
        }

        $settings = array_merge($current, $values);

        Setting::query()->updateOrCreate(
            ['key' => self::KEY],
            [
                'group' => self::GROUP,
                'value' => Crypt::encryptString(json_encode($settings, JSON_THROW_ON_ERROR)),
                'type' => 'json',
                'is_public' => false,
            ],
        );
    }

    public static function forget(): void
    {
        Setting::query()->where('key', self::KEY)->delete();
    }
}
