<?php

use App\Models\Plugin;
use App\Models\PluginMigration;
use App\Models\User;
use App\Services\PluginMigrator;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

function makeMigratingPlugin(string $table): array
{
    $folder = '__plugin_migrations_'.uniqid();
    $path = base_path("plugins/{$folder}");

    File::makeDirectory($path.'/database/migrations', 0755, true);
    File::put($path.'/index.php', "<?php\n// plugin bootstrap\n");
    File::put($path.'/plugin.json', json_encode([
        'name' => 'Migration Test Plugin',
        'slug' => $folder,
        'version' => '1.0.0',
    ]));
    File::put($path.'/database/migrations/2026_01_01_000000_create_test_table.php', <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('{$table}', function (Blueprint \$table) {
            \$table->id();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{$table}');
    }
};
PHP);

    $plugin = Plugin::create([
        'name' => 'Migration Test Plugin',
        'slug' => $folder,
        'folder_name' => $folder,
        'version' => '1.0.0',
    ]);

    return [$plugin, $path];
}

it('runs plugin migrations when the plugin is activated', function () {
    $table = '__pl_things_'.uniqid();
    [$plugin, $path] = makeMigratingPlugin($table);

    try {
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $this->actingAs($admin)
            ->post(route('admin.plugins.activate', $plugin))
            ->assertRedirect();

        expect(Schema::hasTable($table))->toBeTrue();
        expect($plugin->fresh()->is_active)->toBeTrue();
        expect(PluginMigration::where('plugin', $plugin->folder_name)->count())->toBe(1);
    } finally {
        Schema::dropIfExists($table);
        if (File::exists($path)) {
            File::deleteDirectory($path);
        }
    }
});

it('rolls plugin migrations back when the plugin is uninstalled', function () {
    $table = '__pl_things_'.uniqid();
    [$plugin, $path] = makeMigratingPlugin($table);

    try {
        $migrator = app(PluginMigrator::class);
        $migrator->migrate($plugin);

        expect(Schema::hasTable($table))->toBeTrue();

        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $this->actingAs($admin)
            ->delete(route('admin.plugins.destroy', $plugin))
            ->assertRedirect();

        expect(Schema::hasTable($table))->toBeFalse();
        expect(PluginMigration::where('plugin', $plugin->folder_name)->count())->toBe(0);
        expect(Plugin::whereKey($plugin->id)->exists())->toBeFalse();
    } finally {
        Schema::dropIfExists($table);
        if (File::exists($path)) {
            File::deleteDirectory($path);
        }
    }
});

it('is idempotent when there are no pending plugin migrations', function () {
    $table = '__pl_things_'.uniqid();
    [$plugin, $path] = makeMigratingPlugin($table);

    try {
        $migrator = app(PluginMigrator::class);

        expect($migrator->migrate($plugin))->toHaveCount(1);
        expect($migrator->migrate($plugin))->toBe([]);
    } finally {
        Schema::dropIfExists($table);
        if (File::exists($path)) {
            File::deleteDirectory($path);
        }
    }
});
