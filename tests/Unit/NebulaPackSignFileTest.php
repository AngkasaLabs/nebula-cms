<?php

use App\Services\CoreReleaseVerifier;
use Tests\TestCase;

uses(TestCase::class);

function sign_with_temp_key(string $zipPath, string $keyPath, string $outDir): array
{
    $script = base_path('tools/nebula-pack.php');

    $command = escapeshellarg(PHP_BINARY)
        .' '.escapeshellarg($script)
        .' sign-file '.escapeshellarg($zipPath)
        .' --version=1.13.0'
        .' --key='.escapeshellarg($keyPath)
        .' --out='.escapeshellarg($outDir)
        .' 2>&1';

    exec($command, $output, $exitCode);

    return [$exitCode, implode("\n", $output)];
}

it('signs a core release archive with a detached signature', function () {
    $dir = sys_get_temp_dir().'/nebula-sign-'.uniqid('', true);
    mkdir($dir.'/out', 0755, true);

    $keypair = sodium_crypto_sign_keypair();
    $keyPath = $dir.'/test-core.key';
    file_put_contents($keyPath, base64_encode(sodium_crypto_sign_secretkey($keypair)));

    config([
        'nebula.package_signing_keys' => [
            'test-core' => base64_encode(sodium_crypto_sign_publickey($keypair)),
        ],
        'nebula.package_signing_key_id' => 'test-core',
    ]);

    $zip = $dir.'/nebulacms-v1.13.0.zip';
    writeValidZip($zip);

    try {
        [$exitCode, $output] = sign_with_temp_key($zip, $keyPath, $dir.'/out');
        expect($exitCode)->toBe(0, $output);

        $sigPath = $dir.'/out/nebulacms-v1.13.0.zip.sig.json';
        expect(file_exists($sigPath))->toBeTrue();

        $manifest = json_decode((string) file_get_contents($sigPath), true);

        expect($manifest['slug'])->toBe('nebula-cms')
            ->and($manifest['type'])->toBe('core')
            ->and($manifest['version'])->toBe('1.13.0')
            ->and($manifest['key_id'])->toBe('test-core')
            ->and($manifest['sha256'])->toBe(hash_file('sha256', $zip))
            ->and($manifest['size'])->toBe(filesize($zip));

        app(CoreReleaseVerifier::class)->verify($zip, $manifest, $manifest['signature'], '1.13.0');
    } finally {
        exec('rm -rf '.escapeshellarg($dir));
    }
});

it('refuses to sign without a version', function () {
    $dir = sys_get_temp_dir().'/nebula-sign-'.uniqid('', true);
    mkdir($dir, 0755, true);

    $keypair = sodium_crypto_sign_keypair();
    $keyPath = $dir.'/test-core.key';
    file_put_contents($keyPath, base64_encode(sodium_crypto_sign_secretkey($keypair)));

    $zip = $dir.'/archive.zip';
    writeValidZip($zip);

    try {
        $script = base_path('tools/nebula-pack.php');

        $command = escapeshellarg(PHP_BINARY)
            .' '.escapeshellarg($script)
            .' sign-file '.escapeshellarg($zip)
            .' --key='.escapeshellarg($keyPath)
            .' 2>&1';

        exec($command, $output, $exitCode);

        expect($exitCode)->toBe(1)
            ->and(implode("\n", $output))->toContain('pass --version');
    } finally {
        exec('rm -rf '.escapeshellarg($dir));
    }
});
