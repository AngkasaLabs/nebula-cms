<?php

namespace App\Http\Controllers;

use App\Installer\EnvWriter;
use App\Installer\InstallerWizardStore;
use Database\Seeders\ContentSampleSeeder;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;

class InstallerController extends Controller
{
    // -------------------------------------------------------------------------
    // Step 1: Welcome & Requirements
    // -------------------------------------------------------------------------

    public function setLocale(Request $request, string $locale): RedirectResponse
    {
        $request->session()->put('installer_locale', $locale);

        return redirect()->back();
    }

    public function welcome(): View
    {
        $requirements = $this->checkRequirements();

        return view('installer.welcome', compact('requirements'));
    }

    // -------------------------------------------------------------------------
    // Step 2: Database
    // -------------------------------------------------------------------------

    public function database(Request $request): View|RedirectResponse
    {
        if (! $this->requirementsPass()) {
            return redirect()->route('installer.welcome');
        }

        $available = $this->availableDrivers();
        $defaultDriver = $available[0] ?? 'mysql';
        $defaultPort = $defaultDriver === 'pgsql' ? '5432' : '3306';

        $data = $request->session()->get('installer.database', [
            'driver' => $defaultDriver,
            'host' => '127.0.0.1',
            'port' => $defaultPort,
            'database' => 'nebula_cms',
            'username' => 'root',
            'password' => '',
        ]);

        $data['driver'] ??= $defaultDriver;
        if (! in_array($data['driver'], $available, true)) {
            $data['driver'] = $defaultDriver;
            $data['port'] = $defaultPort;
        }

        return view('installer.database', compact('data', 'available'));
    }

    public function saveDatabase(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'driver' => ['required', 'in:mysql,pgsql'],
            'host' => ['required', 'string'],
            'port' => ['required', 'numeric', 'between:1,65535'],
            'database' => ['required', 'string'],
            'username' => ['required', 'string'],
            'password' => ['nullable', 'string'],
        ]);

        $unavailable = $this->unavailableDriverMessage($validated['driver']);
        if ($unavailable) {
            return back()->withInput()->withErrors(['driver' => $unavailable]);
        }

        $error = $this->testDbConnection($validated);
        if ($error) {
            return back()->withInput()->withErrors(['connection' => $error]);
        }

        $request->session()->put('installer.database', $validated);

        return redirect()->route('installer.site');
    }

    public function testDatabase(Request $request): JsonResponse
    {
        $data = $request->only(['driver', 'host', 'port', 'database', 'username', 'password']);

        $unavailable = $this->unavailableDriverMessage($data['driver'] ?? 'mysql');
        if ($unavailable) {
            return response()->json(['success' => false, 'message' => $unavailable]);
        }

        $error = $this->testDbConnection($data);

        if ($error) {
            return response()->json(['success' => false, 'message' => $error]);
        }

        return response()->json(['success' => true, 'message' => __('installer.db_connection_success')]);
    }

    // -------------------------------------------------------------------------
    // Step 3: Site Settings
    // -------------------------------------------------------------------------

    public function site(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has('installer.database')) {
            return redirect()->route('installer.database');
        }

        $data = $request->session()->get('installer.site', [
            'app_name' => 'NebulaCMS',
            'app_url' => request()->root(),
            'app_env' => 'production',
            'locale' => 'en',
        ]);

        return view('installer.site', compact('data'));
    }

    public function saveSite(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'app_name' => ['required', 'string', 'max:100'],
            'app_url' => ['required', 'url'],
            'app_env' => ['required', 'in:production,local'],
            'locale' => ['required', 'in:en,id'],
        ]);

        $request->session()->put('installer.site', $validated);

        return redirect()->route('installer.account');
    }

    // -------------------------------------------------------------------------
    // Step 4: Admin Account
    // -------------------------------------------------------------------------

    public function account(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has('installer.site')) {
            return redirect()->route('installer.site');
        }

        $data = $request->session()->get('installer.account', [
            'name' => 'Administrator',
            'email' => '',
        ]);

        return view('installer.account', compact('data'));
    }

    public function saveAccount(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => ['required', 'string'],
        ]);

        $account = [
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
        ];

        $token = Str::random(64);
        InstallerWizardStore::put($token, [
            'database' => $request->session()->get('installer.database'),
            'site' => $request->session()->get('installer.site'),
            'account' => $account,
            'cursor' => 0,
        ]);

        $request->session()->put([
            'installer.account' => $account,
            'installer.wizard_token' => $token,
            'installer.install_cursor' => 0,
        ]);

        return redirect()->route('installer.installing');
    }

    // -------------------------------------------------------------------------
    // Step 5: Installing
    // -------------------------------------------------------------------------

    public function installing(Request $request): View|RedirectResponse
    {
        $token = $request->session()->get('installer.wizard_token');
        $payload = is_string($token) && $token !== '' ? InstallerWizardStore::get($token) : null;

        if (! $payload || ! isset($payload['account'], $payload['database'], $payload['site'])) {
            return redirect()->route('installer.account');
        }

        return view('installer.installing', [
            'installToken' => $token,
            'installCursor' => (int) ($payload['cursor'] ?? 0),
        ]);
    }

    /**
     * AJAX: Run one install sub-step. The client calls repeatedly until `finished` is true.
     */
    public function run(Request $request): JsonResponse
    {
        $token = $request->input('install_token');
        if (! is_string($token) || $token === '') {
            return response()->json(['error' => __('installer.invalid_install_request')], 422);
        }

        $payload = InstallerWizardStore::get($token);
        if (! $payload || ! isset($payload['account'], $payload['database'], $payload['site'])) {
            return response()->json(['error' => __('installer.session_expired')], 422);
        }

        $cursor = (int) ($payload['cursor'] ?? 0);

        if ($cursor < 0 || $cursor > 5) {
            return response()->json(['error' => __('installer.invalid_install')], 422);
        }

        $db = $payload['database'];
        $site = $payload['site'];
        $account = $payload['account'];

        try {
            $label = $this->installStepLabel($cursor);
            $this->runStep($label, function () use ($cursor, $db, $site, $account) {
                $this->executeInstallStep($cursor, $db, $site, $account);
            });

            $payload['cursor'] = $cursor + 1;
            InstallerWizardStore::put($token, $payload);

            $finished = $cursor === 5;

            if ($finished) {
                InstallerWizardStore::forget($token);
                $request->session()->forget([
                    'installer.database',
                    'installer.site',
                    'installer.account',
                    'installer.install_cursor',
                    'installer.wizard_token',
                ]);
                $request->session()->flash('installer_just_finished', true);
            }

            return response()->json([
                'success' => true,
                'stepIndex' => $cursor,
                'step' => ['status' => 'success', 'message' => $label],
                'finished' => $finished,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'stepIndex' => $cursor,
                'step' => ['status' => 'error', 'message' => $e->getMessage()],
            ], 500);
        }
    }

    private function installStepLabel(int $index): string
    {
        return match ($index) {
            0 => __('installer.install_step_1_label'),
            1 => __('installer.install_step_2_label'),
            2 => __('installer.install_step_3_label'),
            3 => __('installer.install_step_4_label'),
            4 => __('installer.install_step_5_label'),
            5 => __('installer.install_step_6_label'),
            default => throw new \InvalidArgumentException('Invalid install step'),
        };
    }

    private function executeInstallStep(int $index, array $db, array $site, array $account): void
    {
        switch ($index) {
            case 0:
                EnvWriter::write([
                    'APP_NAME' => $site['app_name'],
                    'APP_URL' => $site['app_url'],
                    'APP_ENV' => $site['app_env'],
                    'APP_DEBUG' => $site['app_env'] === 'production' ? 'false' : 'true',
                    'APP_LOCALE' => $site['locale'],
                    'APP_FALLBACK_LOCALE' => $site['locale'],
                    'SESSION_DRIVER' => 'file',
                    'SESSION_ENCRYPT' => 'false',
                    'SESSION_COOKIE' => config('session.cookie'),
                    'DB_CONNECTION' => $db['driver'] ?? 'mysql',
                    'DB_HOST' => $db['host'],
                    'DB_PORT' => $db['port'],
                    'DB_DATABASE' => $db['database'],
                    'DB_USERNAME' => $db['username'],
                    'DB_PASSWORD' => $db['password'] ?? '',
                ]);

                return;
            case 1:
                $this->bootstrapApplicationKey();

                return;
            case 2:
                $this->reconfigureDb($db);
                Artisan::call('migrate', ['--force' => true]);
                Artisan::call('db:seed', [
                    '--class' => RoleAndPermissionSeeder::class,
                    '--force' => true,
                ]);

                return;
            case 3:
                $this->reconfigureDb($db);
                $this->createAdminUser($account);

                return;
            case 4:
                $this->reconfigureDb($db);
                Artisan::call('db:seed', [
                    '--class' => ContentSampleSeeder::class,
                    '--force' => true,
                ]);

                return;
            case 5:
                file_put_contents(
                    storage_path('installed.lock'),
                    'Installed on '.now()->toDateTimeString()
                );

                return;
            default:
                throw new \InvalidArgumentException('Invalid install step');
        }
    }

    // -------------------------------------------------------------------------
    // Step 6: Done
    // -------------------------------------------------------------------------

    public function done(Request $request): View|RedirectResponse
    {
        if (! file_exists(storage_path('installed.lock'))) {
            return redirect()->route('installer.installing');
        }

        if (! $request->session()->get('installer_just_finished')) {
            return redirect('/');
        }

        return view('installer.done');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function runStep(string $label, callable $callback): array
    {
        try {
            $callback();

            return ['status' => 'success', 'message' => $label];
        } catch (\Throwable $e) {
            throw new \RuntimeException("{$label}: ".$e->getMessage(), 0, $e);
        }
    }

    /**
     * Ensure .env has a usable APP_KEY. Laravel's key:generate exits without error when
     * preg_replace cannot find an APP_KEY= line, leaving an empty key.
     */
    private function bootstrapApplicationKey(): void
    {
        $path = base_path('.env');
        $examplePath = base_path('.env.example');

        if (! file_exists($path) && file_exists($examplePath)) {
            copy($examplePath, $path);
        }

        $raw = file_exists($path) ? (string) file_get_contents($path) : '';

        if (! preg_match('/^APP_KEY=/m', $raw)) {
            $newContents = $raw === ''
                ? "APP_KEY=\n"
                : rtrim($raw)."\nAPP_KEY=\n";
            file_put_contents($path, $newContents);
        }

        Artisan::call('key:generate', ['--force' => true]);

        $key = $this->readAppKeyFromEnvFile();
        if ($key === null || $key === '') {
            $key = 'base64:'.base64_encode(Encrypter::generateKey(config('app.cipher')));
            EnvWriter::write(['APP_KEY' => $key]);
        }

        config(['app.key' => $key]);
    }

    private function readAppKeyFromEnvFile(): ?string
    {
        $path = base_path('.env');
        if (! file_exists($path)) {
            return null;
        }

        $raw = file_get_contents($path);
        if (! preg_match('/^APP_KEY=(.*)$/m', $raw, $m)) {
            return null;
        }

        $v = trim($m[1]);
        if ($v !== '' && ($v[0] === '"' || $v[0] === "'")) {
            $v = trim($v, "\"'");
        }

        return $v === '' ? null : $v;
    }

    /**
     * @return array<int, string>
     */
    private function availableDrivers(): array
    {
        $drivers = [];

        if (extension_loaded('pdo_mysql')) {
            $drivers[] = 'mysql';
        }

        if (extension_loaded('pdo_pgsql')) {
            $drivers[] = 'pgsql';
        }

        return $drivers;
    }

    private function unavailableDriverMessage(string $driver): ?string
    {
        $extension = $driver === 'pgsql' ? 'pdo_pgsql' : 'pdo_mysql';

        return extension_loaded($extension)
            ? null
            : __('installer.driver_unavailable', ['driver' => $extension]);
    }

    private function testDbConnection(array $config): ?string
    {
        try {
            $dsn = ($config['driver'] ?? 'mysql') === 'pgsql'
                ? "pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}"
                : "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']};charset=utf8mb4";

            new \PDO($dsn, $config['username'], $config['password'] ?? '', [
                \PDO::ATTR_TIMEOUT => 5,
            ]);

            return null;
        } catch (\PDOException $e) {
            return $e->getMessage();
        }
    }

    private function reconfigureDb(array $db): void
    {
        $connection = ($db['driver'] ?? 'mysql') === 'pgsql' ? 'pgsql' : 'mysql';

        config([
            'database.default' => $connection,
            "database.connections.{$connection}.host" => $db['host'],
            "database.connections.{$connection}.port" => $db['port'],
            "database.connections.{$connection}.database" => $db['database'],
            "database.connections.{$connection}.username" => $db['username'],
            "database.connections.{$connection}.password" => $db['password'] ?? '',
        ]);

        DB::purge($connection);
        DB::reconnect($connection);

        // Forget resolved cache driver instances so they re-resolve using the updated connection
        if (app()->bound('cache')) {
            app('cache')->forgetDriver();
        }

        // Re-initialize Spatie's PermissionRegistrar to clear its cached connection reference
        if (app()->bound(\Spatie\Permission\PermissionRegistrar::class)) {
            app(\Spatie\Permission\PermissionRegistrar::class)->initializeCache();
        }
    }

    private function createAdminUser(array $account): void
    {
        // Match RoleAndPermissionSeeder: role name is "Super Admin", guard web
        $user = \App\Models\User::updateOrCreate(
            ['email' => $account['email']],
            [
                'name' => $account['name'],
                'password' => Hash::make($account['password']),
            ]
        );

        if (! $user->hasRole('Super Admin')) {
            $user->assignRole('Super Admin');
        }
    }

    private function checkRequirements(): array
    {
        $phpVersion = phpversion();
        $phpOk = version_compare($phpVersion, '8.3.0', '>=');

        $extensions = ['pdo', 'mbstring', 'openssl', 'tokenizer', 'xml', 'ctype', 'json', 'bcmath', 'fileinfo', 'curl', 'gd'];
        $extResults = [];
        foreach ($extensions as $ext) {
            $extResults[$ext] = extension_loaded($ext);
        }

        // At least one database driver must be present; the database step lets the user pick.
        $drivers = [
            'mysql' => extension_loaded('pdo_mysql'),
            'pgsql' => extension_loaded('pdo_pgsql'),
        ];

        $paths = [
            'storage/' => storage_path(),
            'bootstrap/cache/' => base_path('bootstrap/cache'),
        ];
        $pathResults = [];
        foreach ($paths as $label => $path) {
            $pathResults[$label] = is_writable($path);
        }

        $extensionsOk = ! in_array(false, $extResults, true);
        $pathsOk = ! in_array(false, $pathResults, true);
        $driverOk = in_array(true, $drivers, true);

        return [
            'php' => ['version' => $phpVersion, 'ok' => $phpOk, 'required' => '8.3.0'],
            'extensions' => $extResults,
            'drivers' => $drivers,
            'paths' => $pathResults,
            'ok' => $phpOk && $extensionsOk && $driverOk && $pathsOk,
        ];
    }

    private function requirementsPass(): bool
    {
        return $this->checkRequirements()['ok'];
    }
}
