<?php

use App\Exceptions\PackageVerificationException;
use App\Exceptions\StoreException;
use App\Models\Plugin;
use App\Models\Setting;
use App\Models\User;
use App\Services\PackageBackups;
use App\Services\PackageInstaller;
use App\Services\SecureZipInspector;
use App\Services\StoreInstaller;
use App\Support\PackageSignature;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $this->keypair = sodium_crypto_sign_keypair();

    config([
        'nebula.package_signing_keys' => [
            'test-key' => base64_encode(sodium_crypto_sign_publickey($this->keypair)),
        ],
        'nebula.package_signing_key_id' => 'test-key',
        'nebula.version' => '1.9.0',
        'nebula.package_backup_retention' => 5,
    ]);

    Setting::set('store_url', 'https://store.test');
    Setting::set('store_enabled', true);
});

function storeUpdateZip(string $slug, string $version, string $dir): string
{
    File::ensureDirectoryExists($dir.'/source');

    File::put($dir.'/source/plugin.json', json_encode([
        'name' => 'Demo Plugin',
        'slug' => $slug,
        'version' => $version,
        'requires' => ['cms_version' => '^1.9.0'],
    ], JSON_PRETTY_PRINT));
    File::put($dir.'/source/index.php', "<?php\n// {$version}\n");

    $zipPath = $dir.'/'.$slug.'-'.$version.'.zip';
    $zip = new ZipArchive;

    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Cannot create test zip.');
    }

    foreach (File::files($dir.'/source') as $file) {
        $zip->addFile($file->getPathname(), $file->getFilename());
    }

    $zip->close();
    File::deleteDirectory($dir.'/source');

    return $zipPath;
}

function fakeSignedDownload(string $slug, string $version, string $zipPath, string $keypair): array
{
    $bytes = file_get_contents($zipPath);

    $manifest = [
        'slug' => $slug,
        'type' => 'plugin',
        'version' => $version,
        'sha256' => hash('sha256', $bytes),
        'size' => strlen($bytes),
        'requires' => ['cms_version' => '^1.9.0'],
        'key_id' => 'test-key',
    ];

    $signature = base64_encode(sodium_crypto_sign_detached(
        PackageSignature::canonicalJson(PackageSignature::payload($manifest)),
        sodium_crypto_sign_secretkey($keypair)
    ));

    Http::fake([
        'https://store.test/api/store/v1/packages/'.$slug.'/download*' => Http::response([
            'slug' => $slug,
            'type' => 'plugin',
            'version' => $version,
            'url' => 'https://cdn.test/'.$slug.'.zip',
            'manifest' => $manifest,
            'signature' => $signature,
        ]),
        'https://cdn.test/'.$slug.'.zip' => Http::response($bytes, 200),
    ]);

    return [$manifest, $signature];
}

function installedDemoPlugin(string $slug): void
{
    $path = base_path("plugins/{$slug}");
    File::ensureDirectoryExists($path);
    File::put($path.'/plugin.json', json_encode([
        'name' => 'Demo Plugin',
        'slug' => $slug,
        'version' => '1.0.0',
    ]));
    File::put($path.'/index.php', "<?php\n// old version\n");

    Plugin::create([
        'name' => 'Demo Plugin',
        'slug' => $slug,
        'folder_name' => $slug,
        'version' => '1.0.0',
        'is_active' => true,
        'settings' => ['api_key' => 'keep-me'],
    ]);
}

function cleanupStoreUpdate(string $slug, string $work): void
{
    File::deleteDirectory(base_path("plugins/{$slug}"));
    File::deleteDirectory($work);

    foreach (app(PackageBackups::class)->backupsFor('plugin', $slug) as $backup) {
        @unlink($backup);
    }
}

it('backs up the installed folder before updating and keeps settings', function () {
    $slug = 'store-update-'.uniqid();
    $work = storage_path('app/temp/test-store-update-'.uniqid());

    installedDemoPlugin($slug);

    $zipPath = storeUpdateZip($slug, '1.1.0', $work);
    fakeSignedDownload($slug, '1.1.0', $zipPath, $this->keypair);

    try {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $this->actingAs($admin)
            ->post('/admin/marketplace/install', ['slug' => $slug, 'type' => 'plugin', 'version' => '1.1.0'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $plugin = Plugin::where('folder_name', $slug)->first();

        expect($plugin->version)->toBe('1.1.0');
        expect($plugin->is_active)->toBeTrue();
        expect($plugin->settings)->toBe(['api_key' => 'keep-me']);

        $backups = app(PackageBackups::class)->backupsFor('plugin', $slug);
        expect($backups)->toHaveCount(1);
        expect(File::get(base_path("plugins/{$slug}/index.php")))->toContain('1.1.0');
    } finally {
        cleanupStoreUpdate($slug, $work);
    }
});

it('restores files and metadata when an update fails', function () {
    $slug = 'store-rollback-'.uniqid();
    $work = storage_path('app/temp/test-store-rollback-'.uniqid());

    installedDemoPlugin($slug);

    $zipPath = storeUpdateZip($slug, '2.0.0', $work);
    fakeSignedDownload($slug, '2.0.0', $zipPath, $this->keypair);

    $failingInstaller = new class(app(SecureZipInspector::class)) extends PackageInstaller
    {
        public function installPluginZip(string $zipPath, ?string $expectedSlug = null): Plugin
        {
            File::deleteDirectory(base_path("plugins/{$expectedSlug}"));

            throw new RuntimeException('Simulated install failure.');
        }
    };

    app()->instance(PackageInstaller::class, $failingInstaller);

    try {
        expect(fn () => app(StoreInstaller::class)->install($slug, '2.0.0'))
            ->toThrow(StoreException::class);

        $plugin = Plugin::where('folder_name', $slug)->first();

        expect($plugin->version)->toBe('1.0.0');
        expect($plugin->settings)->toBe(['api_key' => 'keep-me']);
        expect(File::exists(base_path("plugins/{$slug}/index.php")))->toBeTrue();
        expect(File::get(base_path("plugins/{$slug}/index.php")))->toContain('old version');
        expect(app(PackageBackups::class)->backupsFor('plugin', $slug))->toHaveCount(1);
    } finally {
        cleanupStoreUpdate($slug, $work);
    }
});

it('keeps the installed package when an update fails verification', function () {
    $slug = 'store-badsig-'.uniqid();
    $work = storage_path('app/temp/test-store-badsig-'.uniqid());

    installedDemoPlugin($slug);

    $zipPath = storeUpdateZip($slug, '3.0.0', $work);
    $bytes = file_get_contents($zipPath);

    $manifest = [
        'slug' => $slug,
        'type' => 'plugin',
        'version' => '3.0.0',
        'sha256' => hash('sha256', $bytes),
        'size' => strlen($bytes),
        'requires' => [],
        'key_id' => 'test-key',
    ];

    Http::fake([
        'https://store.test/api/store/v1/packages/'.$slug.'/download*' => Http::response([
            'slug' => $slug,
            'type' => 'plugin',
            'version' => '3.0.0',
            'url' => 'https://cdn.test/'.$slug.'.zip',
            'manifest' => $manifest,
            'signature' => base64_encode(str_repeat("\0", SODIUM_CRYPTO_SIGN_BYTES)),
        ]),
        'https://cdn.test/'.$slug.'.zip' => Http::response($bytes, 200),
    ]);

    try {
        expect(fn () => app(StoreInstaller::class)->install($slug, '3.0.0'))
            ->toThrow(PackageVerificationException::class);

        expect(Plugin::where('folder_name', $slug)->value('version'))->toBe('1.0.0');
        expect(File::get(base_path("plugins/{$slug}/index.php")))->toContain('old version');
    } finally {
        cleanupStoreUpdate($slug, $work);
    }
});

it('prunes old package backups', function () {
    $slug = 'store-prune-'.uniqid();
    $path = base_path("plugins/{$slug}");
    File::ensureDirectoryExists($path);
    File::put($path.'/index.php', "<?php\n// prune test\n");

    try {
        $backups = app(PackageBackups::class);
        $backups->create('plugin', $slug);
        $backups->create('plugin', $slug);
        $backups->create('plugin', $slug);

        $backups->prune('plugin', $slug, 2);

        expect($backups->backupsFor('plugin', $slug))->toHaveCount(2);
    } finally {
        File::deleteDirectory($path);

        foreach (app(PackageBackups::class)->backupsFor('plugin', $slug) as $backup) {
            @unlink($backup);
        }
    }
});
