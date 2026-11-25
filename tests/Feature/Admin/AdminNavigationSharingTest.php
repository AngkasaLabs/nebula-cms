<?php

use App\Models\Plugin;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

it('shares ordered sections and normalised plugin navigation with admins', function () {
    $user = User::factory()->create();
    $user->assignRole('Admin');

    $folder = '__pl_nav_sections_'.uniqid();
    $path = base_path("plugins/{$folder}");
    File::makeDirectory($path, 0755, true);

    File::put($path.'/plugin.json', json_encode([
        'name' => 'Nav Sections',
        'slug' => $folder,
        'version' => '1.0.0',
        'admin_navigation' => [
            [
                'title' => 'Legacy item',
                'href' => '/admin/legacy',
                'group' => 'admin',
                'icon' => 'Route',
            ],
            [
                'title' => 'Tool item',
                'href' => '/admin/tool',
                'group' => 'tools',
                'icon' => 'Wrench',
                'order' => 5,
            ],
        ],
    ]));
    File::put($path.'/index.php', "<?php\n");

    $plugin = new Plugin([
        'name' => 'Nav Sections',
        'slug' => $folder,
        'folder_name' => $folder,
        'version' => '1.0.0',
    ]);

    app()->instance('plugins', collect([$plugin]));

    try {
        $this->actingAs($user)
            ->get(route('admin.dashboard', absolute: false))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('adminNavigationSections.0.key', 'main')
                ->where('adminNavigationSections.3.key', 'tools')
                ->where('adminNavigationSections.5.key', 'extensions')
                ->has('adminNavigation', 2)
                ->where('adminNavigation.0.title', 'Tool item')
                ->where('adminNavigation.0.group', 'tools')
                ->where('adminNavigation.1.title', 'Legacy item')
                ->where('adminNavigation.1.group', 'extensions')
            );
    } finally {
        app()->forgetInstance('plugins');
        File::deleteDirectory($path);
    }
});

it('does not expose navigation sections to signed-in members', function () {
    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->get(route('verification.notice', absolute: false))
        ->assertInertia(fn ($page) => $page
            ->where('adminNavigation', [])
            ->where('adminNavigationSections', [])
        );
});
