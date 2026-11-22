<?php

use App\Services\SecureZipInspector;
use Tests\Support\ZipTestHelper;

it('menolak entri zip dengan path traversal', function () {
    $zipPath = ZipTestHelper::createZipFile([
        '../evil.txt' => 'x',
    ]);

    try {
        $inspector = new SecureZipInspector;
        expect(fn () => $inspector->assertSafeArchive($zipPath))
            ->toThrow(InvalidArgumentException::class);
    } finally {
        if (file_exists($zipPath)) {
            unlink($zipPath);
        }
    }
});

it('mengizinkan file notice tanpa ekstensi di zip plugin', function () {
    $zipPath = ZipTestHelper::createZipFile([
        'plugin.json' => '{}',
        'index.php' => '<?php',
        'NOTICE' => 'notice',
        'AUTHORS' => 'author',
    ]);

    try {
        (new SecureZipInspector)->assertPluginZipExtensions($zipPath);
        expect(true)->toBeTrue();
    } finally {
        if (file_exists($zipPath)) {
            unlink($zipPath);
        }
    }
});
