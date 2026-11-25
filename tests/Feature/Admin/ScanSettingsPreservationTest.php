<?php

use App\Models\Plugin;
use App\Models\Theme;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

/**
 * @param  array<string, mixed>  $settings
 */
function d7_write_plugin_manifest(string $path, string $folder, array $settings, string $version = '1.0.0'): void
{
    File::put($path.'/plugin.json', json_encode([
        'name' => 'Scan Settings Plugin',
        'slug' => $folder,
        'version' => $version,
        'settings' => $settings,
    ]));

    if (! File::exists($path.'/index.php')) {
        File::put($path.'/index.php', "<?php\n// scan settings test\n");
    }
}

/**
 * @param  array<string, mixed>  $settings
 */
function d7_write_theme_manifest(string $path, string $folder, array $settings, string $version = '1.0.0'): void
{
    File::put($path.'/theme.json', json_encode([
        'name' => 'Scan Settings Theme',
        'slug' => $folder,
        'version' => $version,
        'settings' => $settings,
    ]));
}

it('menyemai settings plugin dari manifest saat scan pertama', function () {
    $user = User::factory()->create();
    $user->assignRole('Admin');

    $folder = '__d7_pl_seed_'.uniqid();
    $path = base_path("plugins/{$folder}");
    File::makeDirectory($path, 0755, true);

    $settings = ['bank_accounts' => [['bank' => 'BCA', 'number' => '123']], 'payment_instructions' => 'Pay within 24h'];

    d7_write_plugin_manifest($path, $folder, $settings);

    try {
        $this->actingAs($user)->post(route('admin.plugins.scan'))->assertRedirect();

        $plugin = Plugin::where('folder_name', $folder)->first();
        expect($plugin)->not->toBeNull();
        expect($plugin->settings)->toEqualCanonicalizing($settings);
    } finally {
        Plugin::where('folder_name', $folder)->delete();
        if (File::exists($path)) {
            File::deleteDirectory($path);
        }
    }
});

it('tidak menimpa settings plugin yang sudah ada saat scan ulang', function () {
    $user = User::factory()->create();
    $user->assignRole('Admin');

    $folder = '__d7_pl_keep_'.uniqid();
    $path = base_path("plugins/{$folder}");
    File::makeDirectory($path, 0755, true);

    d7_write_plugin_manifest($path, $folder, ['payment_instructions' => 'from manifest']);

    try {
        $this->actingAs($user)->post(route('admin.plugins.scan'))->assertRedirect();

        $plugin = Plugin::where('folder_name', $folder)->firstOrFail();
        $adminSettings = ['payment_instructions' => 'admin edited', 'store_name' => 'My Store'];
        $plugin->update(['settings' => $adminSettings]);

        // Manifest changes on disk, including different settings and version.
        d7_write_plugin_manifest($path, $folder, ['payment_instructions' => 'manifest overwrite attempt'], '2.0.0');

        $this->actingAs($user)->post(route('admin.plugins.scan'))->assertRedirect();

        $fresh = $plugin->fresh();
        expect($fresh->version)->toBe('2.0.0');
        expect($fresh->settings)->toEqualCanonicalizing($adminSettings);
    } finally {
        Plugin::where('folder_name', $folder)->delete();
        if (File::exists($path)) {
            File::deleteDirectory($path);
        }
    }
});

it('tidak menghapus settings plugin saat manifest hilang pada scan ulang', function () {
    $user = User::factory()->create();
    $user->assignRole('Admin');

    $folder = '__d7_pl_missing_'.uniqid();
    $path = base_path("plugins/{$folder}");
    File::makeDirectory($path, 0755, true);

    d7_write_plugin_manifest($path, $folder, ['payment_instructions' => 'seed']);

    try {
        $this->actingAs($user)->post(route('admin.plugins.scan'))->assertRedirect();

        $plugin = Plugin::where('folder_name', $folder)->firstOrFail();
        $adminSettings = ['payment_instructions' => 'admin edited'];
        $plugin->update(['settings' => $adminSettings]);

        File::delete($path.'/plugin.json');

        $this->actingAs($user)->post(route('admin.plugins.scan'))->assertRedirect();

        $fresh = $plugin->fresh();
        expect($fresh)->not->toBeNull();
        expect($fresh->settings)->toEqualCanonicalizing($adminSettings);
    } finally {
        Plugin::where('folder_name', $folder)->delete();
        if (File::exists($path)) {
            File::deleteDirectory($path);
        }
    }
});

it('menyemai settings tema dari manifest saat scan pertama', function () {
    $user = User::factory()->create();
    $user->assignRole('Admin');

    $folder = '__d7_th_seed_'.uniqid();
    $path = base_path("themes/{$folder}");
    File::makeDirectory($path, 0755, true);

    $settings = ['colors' => ['primary' => '#16130E'], 'layout' => ['container_width' => '1240px']];

    d7_write_theme_manifest($path, $folder, $settings);

    try {
        $this->actingAs($user)->post(route('admin.themes.scan'))->assertRedirect();

        $theme = Theme::where('folder_name', $folder)->first();
        expect($theme)->not->toBeNull();
        expect($theme->settings)->toEqualCanonicalizing($settings);
    } finally {
        Theme::where('folder_name', $folder)->delete();
        if (File::exists($path)) {
            File::deleteDirectory($path);
        }
    }
});

it('tidak menghapus settings tema saat manifest hilang pada scan ulang', function () {
    $user = User::factory()->create();
    $user->assignRole('Admin');

    $folder = '__d7_th_missing_'.uniqid();
    $path = base_path("themes/{$folder}");
    File::makeDirectory($path, 0755, true);

    d7_write_theme_manifest($path, $folder, ['colors' => ['primary' => '#000000']]);

    try {
        $this->actingAs($user)->post(route('admin.themes.scan'))->assertRedirect();

        $theme = Theme::where('folder_name', $folder)->firstOrFail();
        $adminSettings = ['colors' => ['primary' => '#8F4326']];
        $theme->update(['settings' => $adminSettings]);

        File::delete($path.'/theme.json');

        $this->actingAs($user)->post(route('admin.themes.scan'))->assertRedirect();

        $fresh = $theme->fresh();
        expect($fresh)->not->toBeNull();
        expect($fresh->settings)->toEqualCanonicalizing($adminSettings);
    } finally {
        Theme::where('folder_name', $folder)->delete();
        if (File::exists($path)) {
            File::deleteDirectory($path);
        }
    }
});

it('tidak menimpa settings tema yang sudah ada saat scan ulang', function () {
    $user = User::factory()->create();
    $user->assignRole('Admin');

    $folder = '__d7_th_keep_'.uniqid();
    $path = base_path("themes/{$folder}");
    File::makeDirectory($path, 0755, true);

    d7_write_theme_manifest($path, $folder, ['colors' => ['primary' => '#000000']]);

    try {
        $this->actingAs($user)->post(route('admin.themes.scan'))->assertRedirect();

        $theme = Theme::where('folder_name', $folder)->firstOrFail();
        $adminSettings = ['colors' => ['primary' => '#8F4326'], 'branding' => ['header_note' => 'kept']];
        $theme->update(['settings' => $adminSettings]);

        d7_write_theme_manifest($path, $folder, ['colors' => ['primary' => '#FF0000']], '2.0.0');

        $this->actingAs($user)->post(route('admin.themes.scan'))->assertRedirect();

        $fresh = $theme->fresh();
        expect($fresh->version)->toBe('2.0.0');
        expect($fresh->settings)->toEqualCanonicalizing($adminSettings);
    } finally {
        Theme::where('folder_name', $folder)->delete();
        if (File::exists($path)) {
            File::deleteDirectory($path);
        }
    }
});
