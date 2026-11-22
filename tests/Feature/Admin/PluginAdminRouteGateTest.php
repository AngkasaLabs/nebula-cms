<?php

use App\Models\Plugin;
use App\Models\User;
use App\Services\PluginRouteRegistrar;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

it('gates plugin routes mounted under the admin prefix', function () {
    $folder = '__plugin_admin_gate_'.uniqid();
    $path = base_path("plugins/{$folder}");

    File::makeDirectory($path, 0755, true);
    File::put($path.'/plugin.json', json_encode([
        'name' => 'Admin Gate Plugin',
        'slug' => $folder,
        'version' => '1.0.0',
        'routes' => [
            'prefix' => 'admin/'.$folder,
        ],
    ]));
    File::put($path.'/routes.php', "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::get('/', fn () => response('ok'));\n");

    try {
        $plugin = Plugin::create([
            'name' => 'Admin Gate Plugin',
            'slug' => $folder,
            'folder_name' => $folder,
            'version' => '1.0.0',
        ]);

        app(PluginRouteRegistrar::class)->registerForPlugins(collect([$plugin]));

        $member = User::factory()->create();

        $this->actingAs($member)
            ->get('/admin/'.$folder)
            ->assertForbidden();

        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $this->actingAs($admin)
            ->get('/admin/'.$folder)
            ->assertOk();
    } finally {
        if (File::exists($path)) {
            File::deleteDirectory($path);
        }
    }
});
