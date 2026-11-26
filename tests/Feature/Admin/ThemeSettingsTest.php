<?php

use App\Models\Theme;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

function theme_user(string $role): User
{
    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function make_theme(string $folder, ?array $schema = null): Theme
{
    $path = base_path("themes/{$folder}");
    File::ensureDirectoryExists($path);

    $manifest = [
        'name' => 'Settings Theme',
        'slug' => $folder,
        'version' => '1.0.0',
    ];

    if ($schema !== null) {
        $manifest['settings_schema'] = $schema;
    }

    File::put($path.'/theme.json', json_encode($manifest));

    return Theme::create([
        'name' => 'Settings Theme',
        'slug' => $folder,
        'folder_name' => $folder,
        'version' => '1.0.0',
        'is_active' => false,
    ]);
}

it('saves nested theme settings from the settings schema', function () {
    $folder = '__th_settings_'.uniqid();
    $theme = make_theme($folder, [
        'fields' => [
            ['key' => 'colors.primary', 'label' => 'Primary', 'type' => 'text'],
            ['key' => 'layout.container_width', 'label' => 'Container', 'type' => 'text'],
            ['key' => 'features.dark_mode', 'label' => 'Dark mode', 'type' => 'boolean'],
        ],
    ]);

    try {
        $this->actingAs(theme_user('Admin'))
            ->patch(route('admin.themes.settings.update', $theme, absolute: false), [
                'colors.primary' => '#16130E',
                'layout.container_width' => '1240px',
                'features.dark_mode' => true,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        expect($theme->fresh()->settings)->toMatchArray([
            'colors' => ['primary' => '#16130E'],
            'layout' => ['container_width' => '1240px'],
            'features' => ['dark_mode' => true],
        ]);
    } finally {
        $theme->delete();
        File::deleteDirectory(base_path("themes/{$folder}"));
    }
});

it('rejects settings for a theme without a schema', function () {
    $folder = '__th_noschema_'.uniqid();
    $theme = make_theme($folder);

    try {
        $this->actingAs(theme_user('Admin'))
            ->patch(route('admin.themes.settings.update', $theme, absolute: false), ['colors.primary' => '#fff'])
            ->assertSessionHas('error');
    } finally {
        $theme->delete();
        File::deleteDirectory(base_path("themes/{$folder}"));
    }
});

it('forbids editors from changing theme settings', function () {
    $folder = '__th_forbidden_'.uniqid();
    $theme = make_theme($folder, [
        'fields' => [['key' => 'colors.primary', 'label' => 'Primary', 'type' => 'text']],
    ]);

    try {
        $this->actingAs(theme_user('Editor'))
            ->patch(route('admin.themes.settings.update', $theme, absolute: false), ['colors.primary' => '#fff'])
            ->assertForbidden();
    } finally {
        $theme->delete();
        File::deleteDirectory(base_path("themes/{$folder}"));
    }
});
