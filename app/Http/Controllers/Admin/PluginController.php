<?php

namespace App\Http\Controllers\Admin;

use App\Contracts\UploadScanner;
use App\Exceptions\PackageSlugMismatchException;
use App\Exceptions\PackageVerificationException;
use App\Exceptions\UploadScanException;
use App\Exceptions\UploadSignatureException;
use App\Http\Controllers\Controller;
use App\Models\Plugin;
use App\Services\PackageInstaller;
use App\Services\PackageVerifier;
use App\Services\PluginMigrator;
use App\Services\PluginRequirementChecker;
use App\Support\InertiaUploadSecurity;
use App\Support\PackageUploadSignature;
use App\Support\PluginHooks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Inertia\Inertia;

class PluginController extends Controller
{
    public function __construct(
        protected PluginRequirementChecker $requirementChecker,
        protected PluginMigrator $migrator,
    ) {
        $this->middleware('permission:manage plugins');
    }

    public function index()
    {
        $plugins = Plugin::query()
            ->orderBy('name')
            ->get()
            ->map(function (Plugin $plugin) {
                $check = $this->requirementChecker->check($plugin);

                return [
                    'id' => $plugin->id,
                    'name' => $plugin->name,
                    'slug' => $plugin->slug,
                    'folder_name' => $plugin->folder_name,
                    'description' => $plugin->description,
                    'version' => $plugin->version,
                    'author' => $plugin->author,
                    'author_url' => $plugin->author_url,
                    'is_active' => $plugin->is_active,
                    'settings' => $plugin->settings,
                    'requires' => $plugin->requires,
                    'compatibility' => $check,
                    'settings_schema' => $plugin->getSettingsSchemaFromDisk(),
                ];
            });

        return Inertia::render('Admin/Plugins/Index', [
            'plugins' => $plugins,
            'uploadSecurity' => InertiaUploadSecurity::extensionZip(),
        ]);
    }

    public function activate(Plugin $plugin)
    {
        if (! $plugin->hasValidStructure()) {
            return redirect()->back()->with('error', 'Plugin structure is invalid. Missing index.php file.');
        }

        $check = $this->requirementChecker->check($plugin);
        if (! $check['ok']) {
            return redirect()->back()->with('error', implode(' ', $check['errors']));
        }

        try {
            $this->migrator->migrate($plugin);
        } catch (\Throwable $e) {
            \Log::error('Plugin migrations failed: '.$e->getMessage(), ['plugin' => $plugin->slug]);

            return redirect()->back()->with('error', 'Plugin migration failed: '.$e->getMessage());
        }

        $plugin->activate();
        $fresh = $plugin->fresh();

        if (function_exists('activity') && auth()->check()) {
            activity()
                ->causedBy(auth()->user())
                ->withProperties(['plugin_id' => $fresh->id, 'slug' => $fresh->slug])
                ->log('plugin_activated');
        }

        do_action(PluginHooks::PLUGIN_ACTIVATE, $fresh);

        $msg = 'Plugin activated successfully';
        if ($check['warnings'] !== []) {
            return redirect()->back()->with('success', $msg)->with('warning', implode(' ', $check['warnings']));
        }

        return redirect()->back()->with('success', $msg);
    }

    public function deactivate(Plugin $plugin)
    {
        $plugin->deactivate();
        $fresh = $plugin->fresh();

        do_action(PluginHooks::PLUGIN_DEACTIVATE, $fresh);

        if (function_exists('activity') && auth()->check()) {
            activity()
                ->causedBy(auth()->user())
                ->withProperties(['plugin_id' => $fresh->id, 'slug' => $fresh->slug])
                ->log('plugin_deactivated');
        }

        return redirect()->back()->with('success', 'Plugin deactivated successfully');
    }

    public function updateSettings(Request $request, Plugin $plugin)
    {
        $schema = $plugin->getSettingsSchemaFromDisk();
        if (! $schema || empty($schema['fields']) || ! is_array($schema['fields'])) {
            return redirect()->back()->with('error', 'This plugin does not define settings_schema.fields in plugin.json.');
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
        $merged = $plugin->settings ?? [];
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

        $plugin->update(['settings' => $merged]);

        return redirect()->back()->with('success', 'Plugin settings saved.');
    }

    public function scan()
    {
        $pluginsPath = base_path('plugins');

        if (! File::exists($pluginsPath)) {
            File::makeDirectory($pluginsPath, 0755, true);

            return redirect()->back()->with('info', 'Plugins directory created. No plugins found.');
        }

        $directories = File::directories($pluginsPath);
        $registered = 0;
        $incompatible = 0;

        foreach ($directories as $directory) {
            $folderName = basename($directory);
            $configPath = $directory.'/plugin.json';

            if (File::exists($configPath)) {
                $config = json_decode(File::get($configPath), true);

                if (! $config || ! isset($config['name']) || ! isset($config['slug']) || ! isset($config['version'])) {
                    continue;
                }

                $model = Plugin::firstOrNew(['folder_name' => $folderName]);

                $model->fill([
                    'name' => $config['name'],
                    'slug' => $config['slug'],
                    'description' => $config['description'] ?? null,
                    'version' => $config['version'],
                    'author' => $config['author'] ?? null,
                    'author_url' => $config['author_url'] ?? null,
                    'requires' => $config['requires'] ?? null,
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
        }

        $message = "Plugins scanned successfully. {$registered} plugin(s) found.";
        if ($incompatible > 0) {
            return redirect()->back()
                ->with('success', $message)
                ->with('warning', "{$incompatible} plugin(s) have unmet requirements and cannot be activated until resolved.");
        }

        return redirect()->back()->with('success', $message);
    }

    public function destroy(Plugin $plugin)
    {
        // Don't allow deleting active plugins
        if ($plugin->is_active) {
            return redirect()->back()->with('error', 'Cannot delete active plugin. Please deactivate it first.');
        }

        $pluginPath = base_path("plugins/{$plugin->folder_name}");
        $uninstall = $pluginPath.'/uninstall.php';

        if (File::exists($uninstall) && File::isFile($uninstall)) {
            try {
                putenv('NEBULA_PLUGIN_UNINSTALL=1');
                require $uninstall;
                putenv('NEBULA_PLUGIN_UNINSTALL=');
            } catch (\Throwable $e) {
                \Log::error('Plugin uninstall script failed: '.$e->getMessage());

                return redirect()->back()->with('error', 'Uninstall script failed: '.$e->getMessage());
            }
        }

        try {
            $this->migrator->rollback($plugin);
        } catch (\Throwable $e) {
            \Log::error('Plugin migration rollback failed: '.$e->getMessage(), ['plugin' => $plugin->slug]);

            return redirect()->back()->with('error', 'Plugin migration rollback failed: '.$e->getMessage());
        }

        if (File::exists($pluginPath)) {
            File::deleteDirectory($pluginPath);
        }

        $plugin->delete();

        return redirect()->back()->with('success', 'Plugin deleted successfully');
    }

    public function upload(Request $request)
    {
        $maxZipKb = (int) config('upload_security.zip_max_kb', 10240);

        $request->validate([
            'plugin' => ['required', 'file', 'mimes:zip', 'max:'.$maxZipKb],
            'signature' => ['required', 'file', 'mimes:json,txt', 'max:64'],
        ]);

        $file = $request->file('plugin');

        try {
            app(UploadScanner::class)->scanPath($file->getRealPath());
        } catch (UploadScanException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }

        try {
            $signature = PackageUploadSignature::parse($request->file('signature'));
            PackageUploadSignature::assertType($signature, 'plugin');

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
            // Bind the signed slug to the archive's own plugin.json.
            app(PackageInstaller::class)->installPluginZip($file->getRealPath(), (string) $signature['slug']);
        } catch (PackageSlugMismatchException $e) {
            return redirect()->back()->with('error', 'Package verification failed: '.$e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->with('error', __('Invalid ZIP: :msg', ['msg' => $e->getMessage()]));
        } catch (\Throwable $e) {
            return redirect()->back()->with('error', 'Failed to upload plugin: '.$e->getMessage());
        }

        return redirect()->back()->with('success', 'Plugin uploaded successfully');
    }
}
