<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;

class SettingsController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:manage settings');
    }

    public function general()
    {
        $settings = Setting::where('group', 'general')
            ->pluck('value', 'key')
            ->toArray();

        $settings['registration_enabled'] = filter_var(
            $settings['registration_enabled'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );
        $settings['registration_default_role'] = $settings['registration_default_role'] ?? '';
        $settings['member_redirect'] = $settings['member_redirect'] ?? '/';

        return Inertia::render('Admin/Settings/General', [
            'settings' => $settings,
            'roles' => Role::query()
                ->where('guard_name', 'web')
                ->orderBy('name')
                ->pluck('name')
                ->values()
                ->all(),
        ]);
    }

    public function seo()
    {
        $settings = Setting::where('group', 'seo')
            ->pluck('value', 'key')
            ->toArray();

        return Inertia::render('Admin/Settings/Seo', [
            'settings' => $settings,
        ]);
    }

    public function update(Request $request, string $group)
    {
        $validated = $request->validate([
            'settings' => 'required|array',
            'settings.member_redirect' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^\/(?!\/)/'],
            'settings.registration_default_role' => [
                'sometimes',
                'nullable',
                'string',
                Rule::exists('roles', 'name')->where('guard_name', 'web'),
            ],
        ]);

        $settings = $validated['settings'];

        if ($group === 'general') {
            if (array_key_exists('registration_enabled', $settings)) {
                $settings['registration_enabled'] = filter_var(
                    $settings['registration_enabled'],
                    FILTER_VALIDATE_BOOLEAN
                );
            }

            if (($settings['member_redirect'] ?? null) === '') {
                $settings['member_redirect'] = '/';
            }
        }

        foreach ($settings as $key => $value) {
            Setting::set($key, $value, $group);
        }

        return redirect()->back()->with('success', 'Pengaturan berhasil diperbarui');
    }

    public function clearCache()
    {
        cache()->flush();

        try {
            Artisan::call('view:clear');
            Artisan::call('config:clear');
            Artisan::call('route:clear');
        } catch (\Exception $e) {
            Log::warning('Gagal membersihkan cache sistem via panel admin: '.$e->getMessage());
        }

        return redirect()->back()->with('success', 'Semua cache (data, views, config, & routes) berhasil dibersihkan');
    }
}
