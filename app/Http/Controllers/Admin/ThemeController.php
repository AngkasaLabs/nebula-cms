<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\UploadScanner;
use App\Exceptions\PackageSlugMismatchException;
use App\Exceptions\PackageVerificationException;
use App\Exceptions\UploadScanException;
use App\Exceptions\UploadSignatureException;
use App\Http\Controllers\Controller;
use App\Models\Theme;
use App\Services\PackageInstaller;
use App\Services\PackageVerifier;
use App\Services\ThemeRequirementChecker;
use App\Support\InertiaUploadSecurity;
use App\Support\PackageUploadSignature;
use App\Support\ThemeHooks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;

class ThemeController extends Controller
{
    public function __construct(
        protected ThemeRequirementChecker $requirementChecker,
        protected PackageInstaller $installer,
    ) {
        $this->middleware('permission:manage themes');
    }

    public function index()
    {
        $themes = Theme::all()->map(function ($theme) {
            // Tambahkan preview URL jika ada
            if ($theme->preview) {
                $theme->preview_url = asset("themes/{$theme->folder_name}/{$theme->preview}");
            }

            $check = $this->requirementChecker->check($theme);

            return [
                'id' => $theme->id,
                'name' => $theme->name,
                'slug' => $theme->slug,
                'folder_name' => $theme->folder_name,
                'description' => $theme->description,
                'version' => $theme->version,
                'author' => $theme->author,
                'is_active' => $theme->is_active,
                'settings' => $theme->settings,
                'preview_url' => $theme->preview_url,
                'has_preview' => $theme->has_preview,
                'compatibility' => $check,
                'settings_schema' => $theme->getSettingsSchemaFromDisk(),
            ];
        });

        return Inertia::render('Admin/Themes/Index', [
            'themes' => $themes,
            'uploadSecurity' => InertiaUploadSecurity::extensionZip(),
        ]);
    }

    public function activate(Theme $theme)
    {
        if (! $theme->hasValidStructure()) {
            return redirect()->back()->with('error', 'Theme structure is invalid. Missing theme.json file.');
        }

        $check = $this->requirementChecker->check($theme);
        if (! $check['ok']) {
            return redirect()->back()->with('error', implode(' ', $check['errors']));
        }

        do_action(ThemeHooks::THEME_BEFORE_ACTIVATE, $theme);

        $theme->activate();
        $fresh = $theme->fresh();

        if (function_exists('activity') && auth()->check()) {
            activity()
                ->causedBy(auth()->user())
                ->withProperties(['theme_id' => $fresh->id, 'slug' => $fresh->slug])
                ->log('theme_activated');
        }

        do_action(ThemeHooks::THEME_AFTER_ACTIVATE, $fresh);

        $this->installer->publishThemeAssets($fresh);

        $msg = 'Theme activated successfully';
        if ($check['warnings'] !== []) {
            return redirect()->back()->with('success', $msg)->with('warning', implode(' ', $check['warnings']));
        }

        return redirect()->back()->with('success', $msg);
    }

    public function deactivate(Theme $theme)
    {
        if (! $theme->is_active) {
            return redirect()->back()->with('error', 'Theme is already inactive.');
        }

        do_action(ThemeHooks::THEME_BEFORE_DEACTIVATE, $theme);

        $theme->is_active = false;
        $theme->save();

        $fresh = $theme->fresh();

        if (function_exists('activity') && auth()->check()) {
            activity()
                ->causedBy(auth()->user())
                ->withProperties(['theme_id' => $fresh->id, 'slug' => $fresh->slug])
                ->log('theme_deactivated');
        }

        do_action(ThemeHooks::THEME_AFTER_DEACTIVATE, $fresh);

        return redirect()->back()->with('success', 'Theme deactivated successfully');
    }

    public function scan()
    {
        $themesPath = base_path('themes');

        if (! File::exists($themesPath)) {
            File::makeDirectory($themesPath, 0755, true);

            return redirect()->back()->with('info', 'Themes directory created. No themes found.');
        }

        $directories = File::directories($themesPath);
        $registered = 0;
        $incompatible = 0;

        foreach ($directories as $directory) {
            $folderName = basename($directory);
            $configPath = $directory.'/theme.json';

            if (! File::exists($configPath)) {
                continue;
            }

            $config = json_decode(File::get($configPath), true);

            if (! $config || ! isset($config['name'], $config['slug'], $config['version'])) {
                continue;
            }

            $model = Theme::firstOrNew(['folder_name' => $folderName]);

            $model->fill([
                'name' => $config['name'],
                'slug' => $config['slug'],
                'description' => $config['description'] ?? null,
                'version' => $config['version'],
                'author' => $config['author'] ?? null,
                'preview' => $config['preview'] ?? null,
            ]);

            // Settings are admin-managed state: seed them only on first
            // registration, never overwrite them on a later scan.
            if (! $model->exists) {
                $model->settings = $config['settings'] ?? null;
            }

            $model->save();

            if (! $this->requirementChecker->check($model)['ok']) {
                $incompatible++;
            }

            $registered++;
        }

        $message = "Themes scanned successfully. {$registered} theme(s) registered.";
        if ($incompatible > 0) {
            return redirect()->back()
                ->with('success', $message)
                ->with('warning', "{$incompatible} theme(s) have unmet requirements and cannot be activated until resolved.");
        }

        return redirect()->back()->with('success', $message);
    }

    public function destroy(Theme $theme)
    {
        // Jangan izinkan menghapus tema yang sedang aktif
        if ($theme->is_active) {
            return redirect()->back()->with('error', 'Cannot delete active theme');
        }

        $themePath = base_path("themes/{$theme->folder_name}");
        $uninstall = $themePath.'/uninstall.php';

        if (File::exists($uninstall) && File::isFile($uninstall)) {
            try {
                putenv('NEBULA_THEME_UNINSTALL=1');
                require $uninstall;
                putenv('NEBULA_THEME_UNINSTALL=');
            } catch (\Throwable $e) {
                \Log::error('Theme uninstall script failed: '.$e->getMessage());

                return redirect()->back()->with('error', 'Uninstall script failed: '.$e->getMessage());
            }
        }

        do_action(ThemeHooks::THEME_BEFORE_DELETE, $theme);

        $snapshot = [
            'id' => $theme->id,
            'name' => $theme->name,
            'slug' => $theme->slug,
            'folder_name' => $theme->folder_name,
        ];

        // Hapus folder tema jika ada
        $themePath = base_path("themes/{$theme->folder_name}");
        if (File::exists($themePath)) {
            File::deleteDirectory($themePath);
        }

        // Hapus published assets di public
        $publicPath = public_path("themes/{$theme->folder_name}");
        if (File::exists($publicPath)) {
            File::deleteDirectory($publicPath);
        }

        // Hapus dari database
        $theme->delete();

        do_action(ThemeHooks::THEME_AFTER_DELETE, $snapshot);

        if (function_exists('activity') && auth()->check()) {
            activity()
                ->causedBy(auth()->user())
                ->withProperties(['theme_id' => $snapshot['id'], 'slug' => $snapshot['slug']])
                ->log('theme_deleted');
        }

        return redirect()->back()->with('success', 'Theme deleted successfully');
    }

    public function upload(Request $request)
    {
        $maxZipKb = (int) config('upload_security.zip_max_kb', 10240);

        $request->validate([
            'theme' => ['required', 'file', 'mimes:zip', 'max:'.$maxZipKb],
            'signature' => ['required', 'file', 'mimes:json,txt', 'max:64'],
        ]);

        $file = $request->file('theme');

        try {
            app(UploadScanner::class)->scanPath($file->getRealPath());
        } catch (UploadScanException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        try {
            $signature = PackageUploadSignature::parse($request->file('signature'));
            PackageUploadSignature::assertType($signature, 'theme');

            // Trust check happens before the installer touches the filesystem.
            app(PackageVerifier::class)->verify(
                $file->getRealPath(),
                $signature,
                $signature['signature'],
                $signature['key_id'] ?? null,
            );
        } catch (UploadSignatureException|PackageVerificationException $e) {
            return redirect()->back()->with('error', 'Package verification failed: '.$e->getMessage());
        }

        try {
            // Bind the signed slug to the archive's own theme.json.
            $this->installer->installThemeZip($file->getRealPath(), (string) $signature['slug']);
        } catch (PackageSlugMismatchException $e) {
            return redirect()->back()->with('error', 'Package verification failed: '.$e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->with('error', __('Invalid ZIP: :msg', ['msg' => $e->getMessage()]));
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', 'Failed to upload theme: '.$e->getMessage());
        }

        return redirect()->back()->with('success', 'Theme uploaded successfully');
    }

    public function updateSettings(Request $request, Theme $theme)
    {
        $schema = $theme->getSettingsSchemaFromDisk();
        if (! $schema || empty($schema['fields']) || ! is_array($schema['fields'])) {
            return redirect()->back()->with('error', 'This theme does not define settings_schema.fields in theme.json.');
        }

        $rules = [];
        foreach ($schema['fields'] as $field) {
            if (! is_array($field) || empty($field['key']) || ! is_string($field['key'])) {
                continue;
            }
            $key = $field['key'];
            $type = $field['type'] ?? 'text';
            $rules[$key] = match ($type) {
                'boolean', 'bool' => ['nullable', 'boolean'],
                'number', 'integer', 'int' => ['nullable', 'numeric'],
                'color' => ['nullable', 'regex:/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/'],
                default => ['nullable', 'string', 'max:65535'],
            };
        }

        if ($rules === []) {
            return redirect()->back()->with('error', 'Invalid settings schema: no field keys.');
        }

        $validated = $request->validate($rules);

        // Merge nested settings using dot notation
        $merged = $theme->settings ?? [];
        foreach ($validated as $key => $value) {
            if (str_contains($key, '.')) {
                // Handle nested keys like "colors.primary"
                $keys = explode('.', $key);
                $current = &$merged;
                foreach ($keys as $i => $k) {
                    if ($i === count($keys) - 1) {
                        $current[$k] = $value;
                    } else {
                        if (! isset($current[$k]) || ! is_array($current[$k])) {
                            $current[$k] = [];
                        }
                        $current = &$current[$k];
                    }
                }
                unset($current);
            } else {
                $merged[$key] = $value;
            }
        }

        $theme->update(['settings' => $merged]);

        return redirect()->back()->with('success', 'Theme settings saved.');
    }
}
