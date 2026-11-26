<?php

use App\Models\Plugin;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

it('saves dotted plugin settings keys', function () {
    $user = User::factory()->create();
    $user->assignRole('Admin');

    $folder = '__pl_settings_'.uniqid();
    $path = base_path("plugins/{$folder}");
    File::makeDirectory($path, 0755, true);

    File::put($path.'/plugin.json', json_encode([
        'name' => 'Settings Plugin',
        'slug' => $folder,
        'version' => '1.0.0',
        'settings_schema' => [
            'fields' => [
                ['key' => 'profile.store_name', 'label' => 'Store name', 'type' => 'text'],
                ['key' => 'payments.enabled', 'label' => 'Payments', 'type' => 'boolean'],
                ['key' => 'currency', 'label' => 'Currency', 'type' => 'text'],
            ],
        ],
    ]));
    File::put($path.'/index.php', "<?php\n");

    $plugin = Plugin::create([
        'name' => 'Settings Plugin',
        'slug' => $folder,
        'folder_name' => $folder,
        'version' => '1.0.0',
        'is_active' => false,
    ]);

    try {
        $this->actingAs($user)
            ->patch(route('admin.plugins.settings.update', $plugin, absolute: false), [
                'profile.store_name' => 'My Store',
                'payments.enabled' => true,
                'currency' => 'IDR',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        expect($plugin->fresh()->settings)->toMatchArray([
            'profile' => ['store_name' => 'My Store'],
            'payments' => ['enabled' => true],
            'currency' => 'IDR',
        ]);
    } finally {
        $plugin->delete();
        File::deleteDirectory($path);
    }
});
