<?php

use App\Models\User;
use App\Services\Backup\DatabaseDumper;
use App\Services\BackupService;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\File;
use Inertia\Testing\AssertableInertia as Assert;
use Mockery\MockInterface;

beforeEach(function () {
    $this->seed(RoleAndPermissionSeeder::class);
});

function backup_admin(): User
{
    $user = User::factory()->create();
    $user->assignRole('Admin');

    return $user;
}

function backup_fixture(): array
{
    $root = storage_path('app/test-backup-root-'.uniqid());
    $directory = storage_path('app/test-backups-'.uniqid());

    File::ensureDirectoryExists($root.'/app/Http');
    File::ensureDirectoryExists($root.'/resources/views');
    File::ensureDirectoryExists($root.'/public/build');
    File::ensureDirectoryExists($root.'/themes/default');
    File::put($root.'/app/Http/Marker.php', 'original');
    File::put($root.'/resources/views/home.blade.php', '<h1>Home</h1>');
    File::put($root.'/public/build/app.js', 'console.log("x")');
    File::put($root.'/themes/default/theme.json', '{"name":"Default"}');
    File::put($root.'/artisan', '#!/usr/bin/env php');
    File::put($root.'/.env', 'APP_KEY=secret');
    File::ensureDirectoryExists($root.'/vendor/laravel');
    File::put($root.'/vendor/laravel/big.php', 'vendor file');

    return [$root, $directory];
}

function fake_dumper(): MockInterface
{
    $dumper = Mockery::mock(DatabaseDumper::class);

    $dumper->shouldReceive('dump')->andReturnUsing(function (string $directory): array {
        File::put($directory.'/database-backup.sql', '-- fake dump');

        return ['file' => 'database-backup.sql', 'driver' => 'mysql'];
    });

    return $dumper;
}

it('creates a full backup with a database dump and no secrets or vendor files', function () {
    [$root, $directory] = backup_fixture();
    $service = new BackupService(fake_dumper(), $root, $directory);

    $backup = $service->create('manual');

    expect(File::exists($backup['path']))->toBeTrue();

    $zip = new ZipArchive;
    expect($zip->open($backup['path']))->toBeTrue();

    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $names[] = $zip->getNameIndex($i);
    }

    $zip->close();

    expect($names)->toContain('app/Http/Marker.php')
        ->and($names)->toContain('database-backup.sql')
        ->and($names)->toContain('backup-manifest.json')
        ->and($names)->not->toContain('.env')
        ->and(collect($names)->contains(fn (string $name) => str_contains($name, 'vendor/')))->toBeFalse();

    File::deleteDirectory($root);
    File::deleteDirectory($directory);
});

it('restores files from a backup and asks the dumper to restore the database', function () {
    [$root, $directory] = backup_fixture();

    $dumper = fake_dumper();
    $dumper->shouldReceive('restore')->once()->andReturnNull();

    $service = new BackupService($dumper, $root, $directory);
    $backup = $service->create('manual');

    File::put($root.'/app/Http/Marker.php', 'changed');

    $result = $service->restore($backup['path']);

    expect($result['success'])->toBeTrue()
        ->and(File::get($root.'/app/Http/Marker.php'))->toBe('original');

    File::deleteDirectory($root);
    File::deleteDirectory($directory);
});

it('rejects files that are not NebulaCMS backups', function () {
    [$root, $directory] = backup_fixture();
    File::ensureDirectoryExists($directory);

    $path = $directory.'/backup-fake.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString('random.txt', 'nope');
    $zip->close();

    $service = new BackupService(fake_dumper(), $root, $directory);

    expect($service->readManifest($path))->toBeNull()
        ->and($service->restore($path)['success'])->toBeFalse();

    File::deleteDirectory($root);
    File::deleteDirectory($directory);
});

it('lists, downloads and deletes backups through the admin endpoints', function () {
    [$root, $directory] = backup_fixture();

    $dumper = fake_dumper();
    $dumper->shouldReceive('restore')->andReturnNull();

    $service = new BackupService($dumper, $root, $directory);
    $backup = $service->create('manual');

    $this->app->instance(BackupService::class, $service);

    $this->actingAs(backup_admin())
        ->get(route('admin.settings.backups', absolute: false))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Settings/Backups')
            ->has('backups', 1)
            ->where('backups.0.filename', $backup['filename']));

    $this->actingAs(backup_admin())
        ->get(route('admin.settings.backups.download', $backup['filename'], absolute: false))
        ->assertOk();

    $this->actingAs(backup_admin())
        ->delete(route('admin.settings.backups.destroy', $backup['filename'], absolute: false))
        ->assertSessionHas('success');

    expect(File::exists($backup['path']))->toBeFalse();

    File::deleteDirectory($root);
    File::deleteDirectory($directory);
});

it('requires the RESTORE confirmation before restoring', function () {
    [$root, $directory] = backup_fixture();
    $service = new BackupService(fake_dumper(), $root, $directory);
    $backup = $service->create('manual');

    $this->app->instance(BackupService::class, $service);

    $this->actingAs(backup_admin())
        ->post(route('admin.settings.backups.restore', $backup['filename'], absolute: false), [
            'confirmation' => 'yes',
        ])
        ->assertSessionHasErrors('confirmation');

    File::deleteDirectory($root);
    File::deleteDirectory($directory);
});

it('creates a scheduled backup from the console command', function () {
    [$root, $directory] = backup_fixture();
    $service = new BackupService(fake_dumper(), $root, $directory);

    $this->app->instance(BackupService::class, $service);

    $this->artisan('backup:run')->assertSuccessful();

    $backups = $service->listBackups();

    expect($backups)->toHaveCount(1)
        ->and($backups[0]['filename'])->toContain('backup-scheduled-');

    File::deleteDirectory($root);
    File::deleteDirectory($directory);
});
