<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\PackageVerificationException;
use App\Exceptions\StoreException;
use App\Http\Controllers\Controller;
use App\Services\StoreClient;
use App\Services\StoreInstaller;
use App\Services\StoreUpdates;
use Composer\Semver\Semver;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StoreController extends Controller
{
    public function __construct(
        protected StoreClient $client,
        protected StoreUpdates $updates,
        protected StoreInstaller $installer,
    ) {
        $this->middleware('permission:store.install');
    }

    public function index(Request $request): Response
    {
        $type = $request->query('type');
        $type = in_array($type, ['plugin', 'theme'], true) ? $type : null;

        $search = $request->query('q');
        $search = is_string($search) && trim($search) !== '' ? trim($search) : null;

        $page = max(1, (int) $request->integer('page', 1));

        $error = null;
        $packages = [];
        $meta = ['current_page' => 1, 'last_page' => 1, 'total' => 0, 'per_page' => 20];

        if (! $this->client->enabled()) {
            $error = 'The package store is disabled. Enable it in Settings → General.';
        } else {
            try {
                $payload = $this->client->catalog(array_filter([
                    'type' => $type,
                    'q' => $search,
                    'page' => $page,
                    'per_page' => 20,
                ], fn ($value) => $value !== null));

                $packages = $payload['data'];
                $meta = array_merge($meta, $payload['meta']);
            } catch (StoreException $e) {
                $error = $e->getMessage();
            }
        }

        $installed = $this->updates->installedMap();
        $updates = $this->updates->map();
        $cmsVersion = (string) config('nebula.version', '0.0.0');

        $packages = array_map(function (array $package) use ($installed, $updates, $cmsVersion) {
            $packageType = is_string($package['type'] ?? null) ? $package['type'] : 'plugin';
            $slug = is_string($package['slug'] ?? null) ? $package['slug'] : '';
            $key = $packageType.':'.$slug;

            $latest = is_string($package['latest_version'] ?? null) ? $package['latest_version'] : null;
            $installedVersion = $installed[$key] ?? null;
            $requires = is_array($package['requires'] ?? null) ? $package['requires'] : [];
            $compatible = $this->isCompatible($requires, $cmsVersion);

            if (! $compatible) {
                $status = 'incompatible';
            } elseif ($installedVersion !== null) {
                $status = $latest !== null && version_compare($latest, $installedVersion, '>')
                    ? 'update_available'
                    : 'installed';
            } else {
                $status = 'not_installed';
            }

            return array_merge($package, [
                'installed_version' => $installedVersion,
                'update_available' => $status === 'update_available',
                'compatible' => $compatible,
                'status' => $status,
                'latest_changelog' => $updates[$key]['changelog'] ?? null,
            ]);
        }, $packages);

        return Inertia::render('Admin/Store/Index', [
            'packages' => $packages,
            'meta' => $meta,
            'filters' => [
                'type' => $type,
                'q' => $search,
            ],
            'storeUrl' => $this->client->baseUrl(),
            'storeEnabled' => $this->client->enabled(),
            'error' => $error,
            'updatedCount' => count($updates),
            'checkedAt' => $this->updates->cached()['checked_at'] ?? null,
            'cmsVersion' => $cmsVersion,
        ]);
    }

    public function install(Request $request)
    {
        $validated = $request->validate([
            'slug' => ['required', 'string', 'max:191', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
            'version' => ['nullable', 'string', 'max:50'],
            'type' => ['required', 'in:plugin,theme'],
        ]);

        try {
            $result = $this->installer->install($validated['slug'], $validated['version'] ?? null);
        } catch (StoreException|PackageVerificationException $e) {
            return redirect()->back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            report($e);

            return redirect()->back()->with('error', 'Installation failed: '.$e->getMessage());
        }

        $this->updates->forget();
        $this->client->forgetCatalogCache();

        $manager = $result['type'] === 'theme' ? 'Themes' : 'Plugins';

        $message = "{$result['name']} {$result['version']} installed.";

        if ($result['backup_created'] ?? false) {
            $message .= ' The previous version was backed up and will be restored automatically if a future update fails.';
        }

        $message .= " Open {$manager} to activate it.";

        return redirect()->back()->with('success', $message);
    }

    public function refresh()
    {
        $this->client->forgetCatalogCache();

        try {
            $this->updates->refresh();
        } catch (\Throwable $e) {
            report($e);
        }

        return redirect()->back()->with('success', 'Store catalog and update information refreshed.');
    }

    /**
     * @param  array<string, mixed>  $requires
     */
    protected function isCompatible(array $requires, string $cmsVersion): bool
    {
        $constraint = $requires['cms_version'] ?? null;

        if (! is_string($constraint) || $constraint === '') {
            return true;
        }

        try {
            return Semver::satisfies($cmsVersion, $constraint);
        } catch (\Throwable) {
            return false;
        }
    }
}
