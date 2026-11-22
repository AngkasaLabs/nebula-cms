<?php

use App\Models\Plugin;
use App\Models\Setting;
use App\Models\Theme;
use App\Models\User;
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
    ]);

    Setting::set('store_url', 'https://store.test');
    Setting::set('store_enabled', true);
});

function makePluginZip(string $slug, string $version, string $dir): string
{
    File::ensureDirectoryExists($dir);

    $source = $dir.'/source';
    File::ensureDirectoryExists($source);
    File::put($source.'/plugin.json', json_encode([
        'name' => 'Demo Plugin',
        'slug' => $slug,
        'version' => $version,
        'requires' => ['cms_version' => '^1.9.0'],
    ], JSON_PRETTY_PRINT));
    File::put($source.'/index.php', "<?php\n// demo plugin\n");

    $zipPath = $dir.'/'.$slug.'.zip';
    $zip = new ZipArchive;

    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Cannot create test zip.');
    }

    foreach (File::files($source) as $file) {
        $zip->addFile($file->getPathname(), $file->getFilename());
    }

    $zip->close();
    File::deleteDirectory($source);

    return $zipPath;
}

it('installs a signed plugin from the store', function () {
    $slug = 'store-demo-'.uniqid();
    $work = storage_path('app/temp/test-store-'.uniqid());
    $zipPath = makePluginZip($slug, '1.0.0', $work);
    $zipBytes = file_get_contents($zipPath);

    $manifest = [
        'slug' => $slug,
        'type' => 'plugin',
        'version' => '1.0.0',
        'sha256' => hash('sha256', $zipBytes),
        'size' => strlen($zipBytes),
        'requires' => ['cms_version' => '^1.9.0'],
        'key_id' => 'test-key',
    ];

    $signature = base64_encode(sodium_crypto_sign_detached(
        PackageSignature::canonicalJson(PackageSignature::payload($manifest)),
        sodium_crypto_sign_secretkey($this->keypair)
    ));

    Http::fake([
        'https://store.test/api/store/v1/packages/'.$slug.'/download*' => Http::response([
            'slug' => $slug,
            'type' => 'plugin',
            'version' => '1.0.0',
            'url' => 'https://cdn.test/'.$slug.'.zip',
            'manifest' => $manifest,
            'signature' => $signature,
        ]),
        'https://cdn.test/'.$slug.'.zip' => Http::response($zipBytes, 200),
    ]);

    $pluginPath = base_path("plugins/{$slug}");

    try {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $this->actingAs($admin)
            ->post('/admin/marketplace/install', [
                'slug' => $slug,
                'type' => 'plugin',
                'version' => '1.0.0',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        expect(File::exists($pluginPath.'/index.php'))->toBeTrue();

        $plugin = Plugin::where('folder_name', $slug)->first();
        expect($plugin)->not->toBeNull();
        expect($plugin->version)->toBe('1.0.0');
        expect($plugin->is_active)->toBeFalse();
    } finally {
        File::deleteDirectory($pluginPath);
        File::deleteDirectory($work);
    }
});

it('rejects a package whose signature does not match', function () {
    $slug = 'store-bad-'.uniqid();
    $work = storage_path('app/temp/test-store-'.uniqid());
    $zipPath = makePluginZip($slug, '1.0.0', $work);
    $zipBytes = file_get_contents($zipPath);

    $manifest = [
        'slug' => $slug,
        'type' => 'plugin',
        'version' => '1.0.0',
        'sha256' => hash('sha256', $zipBytes),
        'size' => strlen($zipBytes),
        'requires' => [],
        'key_id' => 'test-key',
    ];

    Http::fake([
        'https://store.test/api/store/v1/packages/'.$slug.'/download*' => Http::response([
            'slug' => $slug,
            'type' => 'plugin',
            'version' => '1.0.0',
            'url' => 'https://cdn.test/'.$slug.'.zip',
            'manifest' => $manifest,
            'signature' => base64_encode(str_repeat("\0", SODIUM_CRYPTO_SIGN_BYTES)),
        ]),
        'https://cdn.test/'.$slug.'.zip' => Http::response($zipBytes, 200),
    ]);

    $pluginPath = base_path("plugins/{$slug}");

    try {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $this->actingAs($admin)
            ->post('/admin/marketplace/install', ['slug' => $slug, 'type' => 'plugin'])
            ->assertRedirect()
            ->assertSessionHas('error');

        expect(File::exists($pluginPath))->toBeFalse();
        expect(Plugin::where('folder_name', $slug)->exists())->toBeFalse();
    } finally {
        File::deleteDirectory($pluginPath);
        File::deleteDirectory($work);
    }
});

it('blocks users without the store.install permission', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('access admin');

    $this->actingAs($user)->get('/admin/marketplace')->assertForbidden();
});

function makeThemeZip(string $slug, string $version, string $dir): string
{
    File::ensureDirectoryExists($dir.'/source/assets/css');
    File::ensureDirectoryExists($dir.'/source/views/layouts');

    File::put($dir.'/source/theme.json', json_encode([
        'name' => 'Demo Theme',
        'slug' => $slug,
        'version' => $version,
        'requires' => ['cms_version' => '^1.9.0'],
    ], JSON_PRETTY_PRINT));
    File::put($dir.'/source/assets/css/style.css', 'body { color: #000; }');
    File::put($dir.'/source/views/layouts/app.blade.php', '<html><body>@yield("content")</body></html>');

    $zipPath = $dir.'/'.$slug.'.zip';
    $zip = new ZipArchive;

    if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Cannot create test zip.');
    }

    $root = realpath($dir.'/source');
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if (! $file->isFile()) {
            continue;
        }

        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        $zip->addFile($file->getPathname(), $relative);
    }

    $zip->close();
    File::deleteDirectory($dir.'/source');

    return $zipPath;
}

it('installs a signed theme from the store and publishes its assets', function () {
    $slug = 'store-theme-'.uniqid();
    $work = storage_path('app/temp/test-store-theme-'.uniqid());
    $zipPath = makeThemeZip($slug, '1.0.0', $work);
    $zipBytes = file_get_contents($zipPath);

    $manifest = [
        'slug' => $slug,
        'type' => 'theme',
        'version' => '1.0.0',
        'sha256' => hash('sha256', $zipBytes),
        'size' => strlen($zipBytes),
        'requires' => ['cms_version' => '^1.9.0'],
        'key_id' => 'test-key',
    ];

    $signature = base64_encode(sodium_crypto_sign_detached(
        PackageSignature::canonicalJson(PackageSignature::payload($manifest)),
        sodium_crypto_sign_secretkey($this->keypair)
    ));

    Http::fake([
        'https://store.test/api/store/v1/packages/'.$slug.'/download*' => Http::response([
            'slug' => $slug,
            'type' => 'theme',
            'version' => '1.0.0',
            'url' => 'https://cdn.test/'.$slug.'.zip',
            'manifest' => $manifest,
            'signature' => $signature,
        ]),
        'https://cdn.test/'.$slug.'.zip' => Http::response($zipBytes, 200),
    ]);

    $themePath = base_path("themes/{$slug}");
    $publicPath = public_path("themes/{$slug}");

    try {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $this->actingAs($admin)
            ->post('/admin/marketplace/install', ['slug' => $slug, 'type' => 'theme'])
            ->assertRedirect()
            ->assertSessionHas('success');

        expect(File::exists($themePath.'/theme.json'))->toBeTrue();
        expect(File::exists($publicPath.'/assets/css/style.css'))->toBeTrue();

        $theme = Theme::where('folder_name', $slug)->first();
        expect($theme)->not->toBeNull();
        expect($theme->version)->toBe('1.0.0');
        expect($theme->is_active)->toBeFalse();
    } finally {
        File::deleteDirectory($themePath);
        File::deleteDirectory($publicPath);
        File::deleteDirectory($work);
    }
});
