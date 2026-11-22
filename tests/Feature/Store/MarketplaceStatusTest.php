<?php

use App\Models\Plugin;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);

    Setting::set('store_url', 'https://store.test');
    Setting::set('store_enabled', true);

    config(['nebula.version' => '1.9.0']);
});

function fakeStoreCatalog(array $packages): void
{
    Http::fake([
        'https://store.test/api/store/v1/packages*' => Http::response([
            'data' => $packages,
            'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => count($packages)],
        ]),
    ]);
}

it('marks an installed package with a newer store version as update available', function () {
    Plugin::create([
        'name' => 'Demo Plugin',
        'slug' => 'demo-plugin',
        'folder_name' => 'demo-plugin',
        'version' => '1.0.0',
    ]);

    fakeStoreCatalog([[
        'slug' => 'demo-plugin',
        'type' => 'plugin',
        'name' => 'Demo Plugin',
        'latest_version' => '1.2.0',
        'requires' => ['cms_version' => '^1.9.0'],
        'downloads' => 10,
        'pricing' => 'free',
    ]]);

    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $this->actingAs($admin)
        ->get('/admin/marketplace')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Admin/Store/Index')
            ->where('packages.0.status', 'update_available')
            ->where('packages.0.installed_version', '1.0.0')
            ->where('packages.0.compatible', true)
        );
});

it('marks packages requiring a newer CMS as incompatible', function () {
    fakeStoreCatalog([[
        'slug' => 'future-plugin',
        'type' => 'plugin',
        'name' => 'Future Plugin',
        'latest_version' => '2.0.0',
        'requires' => ['cms_version' => '^9.0.0'],
        'downloads' => 0,
        'pricing' => 'free',
    ]]);

    $admin = User::factory()->create();
    $admin->assignRole('Admin');

    $this->actingAs($admin)
        ->get('/admin/marketplace')
        ->assertInertia(fn ($page) => $page
            ->where('packages.0.status', 'incompatible')
            ->where('packages.0.compatible', false)
        );
});
