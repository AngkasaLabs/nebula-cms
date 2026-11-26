<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Support\MailSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Throwable;

class MailSettingsController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:manage settings');
    }

    public function edit()
    {
        $settings = MailSettings::forDisplay();

        return Inertia::render('Admin/Settings/Email', [
            'settings' => [
                'host' => $settings['host'],
                'port' => $settings['port'],
                'encryption' => $settings['encryption'],
                'username' => $settings['username'],
                'from_address' => $settings['from_address'],
                'from_name' => $settings['from_name'],
                'has_password' => MailSettings::hasPassword(),
            ],
            'source' => MailSettings::configured() ? 'settings' : 'env',
            'effective' => [
                'mailer' => config('mail.default'),
                'host' => config('mail.mailers.smtp.host'),
                'from_address' => config('mail.from.address'),
            ],
        ]);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'encryption' => ['nullable', 'string', 'in:tls,ssl,none'],
            'username' => ['nullable', 'string', 'max:255'],
            'password' => ['nullable', 'string', 'max:255'],
            'from_address' => ['required', 'email', 'max:255'],
            'from_name' => ['required', 'string', 'max:255'],
        ]);

        if (($validated['encryption'] ?? '') === 'none') {
            $validated['encryption'] = '';
        }

        MailSettings::save($validated);

        if (function_exists('activity') && auth()->check()) {
            activity()
                ->causedBy(auth()->user())
                ->withProperties(['keys' => array_keys($validated)])
                ->log('mail_settings_updated');
        }

        return redirect()->back()->with('success', 'Pengaturan email berhasil disimpan.');
    }

    public function test(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        MailSettings::applyToConfig();

        try {
            Mail::raw(
                "This is a test email from NebulaCMS.\n\nIf you can read this, outgoing mail works.",
                function ($message) use ($validated) {
                    $message->to($validated['email'])
                        ->subject('NebulaCMS test email');
                },
            );
        } catch (Throwable $e) {
            Log::warning('NebulaCMS: test email failed.', [
                'error' => $e->getMessage(),
            ]);

            return redirect()->back()->with('error', 'Email uji gagal dikirim: '.$e->getMessage());
        }

        return redirect()->back()->with('success', 'Email uji berhasil dikirim ke '.$validated['email'].'.');
    }

    public function reset()
    {
        MailSettings::forget();

        if (function_exists('activity') && auth()->check()) {
            activity()
                ->causedBy(auth()->user())
                ->log('mail_settings_reset');
        }

        return redirect()->back()->with('success', 'Pengaturan email dihapus. NebulaCMS kembali memakai konfigurasi .env.');
    }
}
