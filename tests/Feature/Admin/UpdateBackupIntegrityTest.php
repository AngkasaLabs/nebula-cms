<?php

use App\Services\UpdateService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->backupDir = storage_path('app/backups');
    File::ensureDirectoryExists($this->backupDir);
});

function cleanupBackup(string $file): void
{
    if (File::exists($file)) {
        File::delete($file);
    }
}

it('hides corrupt backup archives from the backup list', function () {
    $good = $this->backupDir.'/pre-update-1.0.0-'.uniqid().'.zip';
    $bad = $this->backupDir.'/pre-update-1.0.0-'.uniqid().'.zip';

    writeValidZip($good);
    file_put_contents($bad, 'not a zip');

    try {
        $names = collect(app(UpdateService::class)->getBackups())->pluck('filename')->all();

        expect($names)->toContain(basename($good));
        expect($names)->not->toContain(basename($bad));
    } finally {
        cleanupBackup($good);
        cleanupBackup($bad);
    }
});

it('refuses to roll back from a corrupt backup', function () {
    $bad = $this->backupDir.'/pre-update-1.0.0-'.uniqid().'.zip';
    file_put_contents($bad, 'not a zip');

    try {
        $result = app(UpdateService::class)->rollback($bad);

        expect($result['success'])->toBeFalse();
        expect($result['message'])->toContain('invalid or incomplete');
    } finally {
        cleanupBackup($bad);
    }
});

it('prunes stale partial backups but keeps a recent one', function () {
    $stale = $this->backupDir.'/pre-update-1.0.0-stale-'.uniqid().'.zip';
    $recent = $this->backupDir.'/pre-update-1.0.0-recent-'.uniqid().'.zip';

    file_put_contents($stale, 'partial');
    file_put_contents($recent, 'partial');
    touch($stale, now()->subHours(2)->timestamp);

    try {
        app(UpdateService::class)->pruneBackups(5);

        expect(File::exists($stale))->toBeFalse();
        expect(File::exists($recent))->toBeTrue();
    } finally {
        cleanupBackup($stale);
        cleanupBackup($recent);
    }
});

it('reports when the CLI updater is already up to date', function () {
    Http::fake([
        'https://api.github.com/repos/*/releases/latest' => Http::response([
            'tag_name' => 'v'.config('nebula.version'),
            'assets' => [],
            'body' => '',
            'html_url' => 'https://github.com/AngkasaLabs/nebula-cms/releases',
            'published_at' => now()->toIso8601String(),
        ]),
    ]);

    $this->artisan('cms:update')
        ->expectsOutputToContain('already up to date')
        ->assertSuccessful();
});

it('archives nested directories with the streaming iterator', function () {
    $dir = storage_path('app/temp/'.uniqid('zip-src-', true));
    $zipPath = $dir.'.zip';

    File::ensureDirectoryExists($dir.'/nested/deep');
    File::put($dir.'/root.txt', 'root');
    File::put($dir.'/nested/deep/file.txt', 'nested');

    $zip = new ZipArchive;

    try {
        expect($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE))->toBeTrue();

        $service = app(UpdateService::class);
        $method = new ReflectionMethod($service, 'addDirectoryToZip');
        $method->setAccessible(true);
        $method->invoke($service, $zip, $dir, 'pkg');

        $zip->close();

        $check = new ZipArchive;
        expect($check->open($zipPath))->toBeTrue();

        $names = [];
        for ($i = 0; $i < $check->numFiles; $i++) {
            $names[] = $check->getNameIndex($i);
        }
        $check->close();

        sort($names);

        expect($names)->toBe(['pkg/nested/deep/file.txt', 'pkg/root.txt']);
    } finally {
        File::deleteDirectory($dir);
        cleanupBackup($zipPath);
    }
});
