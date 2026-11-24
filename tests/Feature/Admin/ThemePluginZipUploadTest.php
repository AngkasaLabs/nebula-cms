<?php

use App\Models\Plugin;
use App\Models\Theme;
use App\Models\User;
use App\Support\PackageSignature;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\Support\ZipTestHelper;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);

    $this->keypair = sodium_crypto_sign_keypair();
    $GLOBALS['d1_test_keypair'] = $this->keypair;

    config([
        'nebula.package_signing_keys' => [
            'test-upload-key' => base64_encode(sodium_crypto_sign_publickey($this->keypair)),
        ],
        'nebula.package_signing_key_id' => 'test-upload-key',
    ]);
});

afterEach(function () {
    unset($GLOBALS['d1_test_keypair']);
});

/**
 * Build the `.sig.json` contents for a package at $zipPath.
 */
function d1_signed_signature(string $zipPath, string $type, string $slug, string $version = '1.0.0'): string
{
    $bytes = file_get_contents($zipPath);

    $manifest = [
        'slug' => $slug,
        'type' => $type,
        'version' => $version,
        'sha256' => hash('sha256', $bytes),
        'size' => strlen($bytes),
        'requires' => ['cms_version' => '^1.9.0'],
        'key_id' => 'test-upload-key',
    ];

    $signature = base64_encode(sodium_crypto_sign_detached(
        PackageSignature::canonicalJson(PackageSignature::payload($manifest)),
        sodium_crypto_sign_secretkey($GLOBALS['d1_test_keypair'])
    ));

    return json_encode($manifest + ['signature' => $signature]);
}

/**
 * @return array{0: UploadedFile, 1: string}
 */
function d1_signature_upload(string $signatureJson, string $name = 'package.sig.json'): array
{
    $path = storage_path('app/sig-test-'.uniqid('', true).'.sig.json');
    file_put_contents($path, $signatureJson);

    return [new UploadedFile($path, $name, 'application/json', null, true), $path];
}

describe('upload tema (ZIP)', function () {
    it('gagal jika ZIP tidak berisi theme.json', function () {
        $user = User::factory()->create();
        $user->assignRole('Admin');

        $zipPath = ZipTestHelper::createZipFile([
            'readme.txt' => 'hanya teks',
        ]);

        [$signature, $signaturePath] = d1_signature_upload(
            d1_signed_signature($zipPath, 'theme', 'zip-theme-missing-'.uniqid())
        );

        try {
            $upload = new UploadedFile($zipPath, 'tema.zip', 'application/zip', null, true);

            $this->actingAs($user)
                ->post(route('admin.themes.upload'), ['theme' => $upload, 'signature' => $signature])
                ->assertRedirect()
                ->assertSessionHas('error');
        } finally {
            @unlink($zipPath);
            @unlink($signaturePath);
        }
    });

    it('berhasil mengunggah tema ZIP valid dan mendaftarkan di database', function () {
        $user = User::factory()->create();
        $user->assignRole('Admin');

        $slug = 'zip-theme-'.uniqid();
        $themeJson = json_encode([
            'name' => 'Zip Upload Theme',
            'slug' => $slug,
            'version' => '2.0.0',
            'description' => 'dari tes',
        ]);

        $zipPath = ZipTestHelper::createZipFile([
            'theme.json' => $themeJson,
        ]);

        [$signature, $signaturePath] = d1_signature_upload(
            d1_signed_signature($zipPath, 'theme', $slug, '2.0.0')
        );

        try {
            $upload = new UploadedFile($zipPath, 'tema.zip', 'application/zip', null, true);

            $this->actingAs($user)
                ->post(route('admin.themes.upload'), ['theme' => $upload, 'signature' => $signature])
                ->assertRedirect()
                ->assertSessionHas('success');

            $theme = Theme::where('folder_name', $slug)->first();
            expect($theme)->not->toBeNull();
            expect($theme->name)->toBe('Zip Upload Theme');
        } finally {
            @unlink($zipPath);
            @unlink($signaturePath);
            $themeDir = base_path("themes/{$slug}");
            if (File::exists($themeDir)) {
                File::deleteDirectory($themeDir);
            }
            Theme::where('folder_name', $slug)->delete();
        }
    });
});

describe('upload plugin (ZIP)', function () {
    it('gagal jika ZIP tidak berisi plugin.json', function () {
        $user = User::factory()->create();
        $user->assignRole('Admin');

        $zipPath = ZipTestHelper::createZipFile([
            'index.php' => '<?php',
        ]);

        [$signature, $signaturePath] = d1_signature_upload(
            d1_signed_signature($zipPath, 'plugin', 'zip-pl-missing-'.uniqid())
        );

        try {
            $upload = new UploadedFile($zipPath, 'plugin.zip', 'application/zip', null, true);

            $this->actingAs($user)
                ->post(route('admin.plugins.upload'), ['plugin' => $upload, 'signature' => $signature])
                ->assertRedirect()
                ->assertSessionHas('error');
        } finally {
            @unlink($zipPath);
            @unlink($signaturePath);
        }
    });

    it('gagal jika ZIP berisi ekstensi yang tidak diizinkan', function () {
        $user = User::factory()->create();
        $user->assignRole('Admin');

        $slug = 'zip-bad-'.uniqid();
        $pluginJson = json_encode([
            'name' => 'Bad Plugin',
            'slug' => $slug,
            'version' => '1.0.0',
        ]);

        $zipPath = ZipTestHelper::createZipFile([
            'plugin.json' => $pluginJson,
            'index.php' => "<?php\n",
            'malware.exe' => 'MZ',
        ]);

        [$signature, $signaturePath] = d1_signature_upload(
            d1_signed_signature($zipPath, 'plugin', $slug)
        );

        try {
            $upload = new UploadedFile($zipPath, 'plugin.zip', 'application/zip', null, true);

            $this->actingAs($user)
                ->post(route('admin.plugins.upload'), ['plugin' => $upload, 'signature' => $signature])
                ->assertRedirect()
                ->assertSessionHas('error');
        } finally {
            @unlink($zipPath);
            @unlink($signaturePath);
        }
    });

    it('berhasil mengunggah plugin ZIP valid', function () {
        $user = User::factory()->create();
        $user->assignRole('Admin');

        $slug = 'zip-pl-'.uniqid();
        $pluginJson = json_encode([
            'name' => 'Zip Upload Plugin',
            'slug' => $slug,
            'version' => '1.2.3',
        ]);

        $zipPath = ZipTestHelper::createZipFile([
            'plugin.json' => $pluginJson,
            'index.php' => "<?php\n// zip plugin test\n",
        ]);

        [$signature, $signaturePath] = d1_signature_upload(
            d1_signed_signature($zipPath, 'plugin', $slug, '1.2.3')
        );

        try {
            $upload = new UploadedFile($zipPath, 'plugin.zip', 'application/zip', null, true);

            $this->actingAs($user)
                ->post(route('admin.plugins.upload'), ['plugin' => $upload, 'signature' => $signature])
                ->assertRedirect()
                ->assertSessionHas('success');

            $plugin = Plugin::where('folder_name', $slug)->first();
            expect($plugin)->not->toBeNull();
            expect($plugin->name)->toBe('Zip Upload Plugin');
        } finally {
            @unlink($zipPath);
            @unlink($signaturePath);
            $pluginDir = base_path("plugins/{$slug}");
            if (File::exists($pluginDir)) {
                File::deleteDirectory($pluginDir);
            }
            Plugin::where('folder_name', $slug)->delete();
        }
    });
});

describe('verifikasi signature upload Admin', function () {
    it('menolak paket yang diubah setelah signing tanpa menulis apa pun', function () {
        $user = User::factory()->create();
        $user->assignRole('Admin');

        $slug = 'zip-tampered-'.uniqid();
        $pluginJson = json_encode([
            'name' => 'Tampered Plugin',
            'slug' => $slug,
            'version' => '1.0.0',
        ]);

        $zipPath = ZipTestHelper::createZipFile([
            'plugin.json' => $pluginJson,
            'index.php' => "<?php\n// clean\n",
        ]);

        [$signature, $signaturePath] = d1_signature_upload(
            d1_signed_signature($zipPath, 'plugin', $slug)
        );

        // Modify the archive after signing so the signed checksum no longer matches.
        $zip = new ZipArchive;
        expect($zip->open($zipPath))->toBeTrue();
        $zip->addFromString('index.php', "<?php\n// tampered after signing\n");
        $zip->close();

        try {
            $upload = new UploadedFile($zipPath, 'plugin.zip', 'application/zip', null, true);

            $this->actingAs($user)
                ->post(route('admin.plugins.upload'), ['plugin' => $upload, 'signature' => $signature])
                ->assertRedirect()
                ->assertSessionHas('error');

            expect(Plugin::where('folder_name', $slug)->exists())->toBeFalse();
            expect(File::exists(base_path("plugins/{$slug}")))->toBeFalse();
        } finally {
            @unlink($zipPath);
            @unlink($signaturePath);
            $pluginDir = base_path("plugins/{$slug}");
            if (File::exists($pluginDir)) {
                File::deleteDirectory($pluginDir);
            }
            Plugin::where('folder_name', $slug)->delete();
        }
    });

    it('menolak upload tanpa file signature', function () {
        $user = User::factory()->create();
        $user->assignRole('Admin');

        $slug = 'zip-nosig-'.uniqid();
        $zipPath = ZipTestHelper::createZipFile([
            'plugin.json' => json_encode([
                'name' => 'Unsigned Plugin',
                'slug' => $slug,
                'version' => '1.0.0',
            ]),
            'index.php' => "<?php\n",
        ]);

        try {
            $upload = new UploadedFile($zipPath, 'plugin.zip', 'application/zip', null, true);

            $this->actingAs($user)
                ->post(route('admin.plugins.upload'), ['plugin' => $upload])
                ->assertRedirect()
                ->assertSessionHasErrors('signature');

            expect(Plugin::where('folder_name', $slug)->exists())->toBeFalse();
        } finally {
            @unlink($zipPath);
        }
    });

    it('menolak signature plugin pada endpoint theme', function () {
        $user = User::factory()->create();
        $user->assignRole('Admin');

        $slug = 'zip-wrong-type-'.uniqid();
        $zipPath = ZipTestHelper::createZipFile([
            'theme.json' => json_encode([
                'name' => 'Wrong Type Theme',
                'slug' => $slug,
                'version' => '1.0.0',
            ]),
        ]);

        [$signature, $signaturePath] = d1_signature_upload(
            d1_signed_signature($zipPath, 'plugin', $slug)
        );

        try {
            $upload = new UploadedFile($zipPath, 'theme.zip', 'application/zip', null, true);

            $this->actingAs($user)
                ->post(route('admin.themes.upload'), ['theme' => $upload, 'signature' => $signature])
                ->assertRedirect()
                ->assertSessionHas('error');

            expect(Theme::where('folder_name', $slug)->exists())->toBeFalse();
            expect(File::exists(base_path("themes/{$slug}")))->toBeFalse();
        } finally {
            @unlink($zipPath);
            @unlink($signaturePath);
            $themeDir = base_path("themes/{$slug}");
            if (File::exists($themeDir)) {
                File::deleteDirectory($themeDir);
            }
            Theme::where('folder_name', $slug)->delete();
        }
    });

    it('menolak signature yang diterbitkan untuk paket lain', function () {
        $user = User::factory()->create();
        $user->assignRole('Admin');

        $slug = 'zip-real-'.uniqid();
        $zipPath = ZipTestHelper::createZipFile([
            'plugin.json' => json_encode([
                'name' => 'Real Plugin',
                'slug' => $slug,
                'version' => '1.0.0',
            ]),
            'index.php' => "<?php\n",
        ]);

        // Signature names a different slug; verification passes for the archive
        // bytes, but the installer must refuse because the slug does not match.
        [$signature, $signaturePath] = d1_signature_upload(
            d1_signed_signature($zipPath, 'plugin', 'zip-other-'.uniqid())
        );

        try {
            $upload = new UploadedFile($zipPath, 'plugin.zip', 'application/zip', null, true);

            $this->actingAs($user)
                ->post(route('admin.plugins.upload'), ['plugin' => $upload, 'signature' => $signature])
                ->assertRedirect()
                ->assertSessionHas('error');

            expect(Plugin::where('folder_name', $slug)->exists())->toBeFalse();
            expect(File::exists(base_path("plugins/{$slug}")))->toBeFalse();
        } finally {
            @unlink($zipPath);
            @unlink($signaturePath);
            $pluginDir = base_path("plugins/{$slug}");
            if (File::exists($pluginDir)) {
                File::deleteDirectory($pluginDir);
            }
            Plugin::where('folder_name', $slug)->delete();
        }
    });

    it('menolak signature JSON yang tidak valid', function () {
        $user = User::factory()->create();
        $user->assignRole('Admin');

        $zipPath = ZipTestHelper::createZipFile([
            'plugin.json' => json_encode([
                'name' => 'Bad Signature Plugin',
                'slug' => 'zip-badsig-'.uniqid(),
                'version' => '1.0.0',
            ]),
            'index.php' => "<?php\n",
        ]);

        [$signature, $signaturePath] = d1_signature_upload('{not-json');

        try {
            $upload = new UploadedFile($zipPath, 'plugin.zip', 'application/zip', null, true);

            $this->actingAs($user)
                ->post(route('admin.plugins.upload'), ['plugin' => $upload, 'signature' => $signature])
                ->assertRedirect()
                ->assertSessionHas('error');
        } finally {
            @unlink($zipPath);
            @unlink($signaturePath);
        }
    });
});
