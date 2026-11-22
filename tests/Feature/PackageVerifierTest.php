<?php

use App\Exceptions\PackageVerificationException;
use App\Services\PackageVerifier;
use App\Support\PackageSignature;

function signedFixture(): array
{
    $keypair = sodium_crypto_sign_keypair();
    $secretKey = sodium_crypto_sign_secretkey($keypair);

    config([
        'nebula.package_signing_keys' => [
            'test-key-1' => base64_encode(sodium_crypto_sign_publickey($keypair)),
        ],
        'nebula.package_signing_key_id' => 'test-key-1',
    ]);

    $zipPath = tempnam(sys_get_temp_dir(), 'pkgverify');
    $contents = 'fake-zip-bytes';
    file_put_contents($zipPath, $contents);

    $manifest = [
        'slug' => 'demo-plugin',
        'type' => 'plugin',
        'version' => '1.0.0',
        'sha256' => hash('sha256', $contents),
        'size' => strlen($contents),
        'requires' => ['cms_version' => '^1.0.0'],
        'key_id' => 'test-key-1',
    ];

    $signature = base64_encode(sodium_crypto_sign_detached(
        PackageSignature::canonicalJson(PackageSignature::payload($manifest)),
        $secretKey
    ));

    return [$zipPath, $manifest, $signature];
}

it('accepts a package with a valid signature and checksum', function () {
    [$zipPath, $manifest, $signature] = signedFixture();

    try {
        app(PackageVerifier::class)->verify($zipPath, $manifest, $signature);
    } finally {
        @unlink($zipPath);
    }

    expect(true)->toBeTrue();
});

it('rejects a manifest that was modified after signing', function () {
    [$zipPath, $manifest, $signature] = signedFixture();
    $manifest['version'] = '1.0.1';

    try {
        app(PackageVerifier::class)->verify($zipPath, $manifest, $signature);
    } finally {
        @unlink($zipPath);
    }
})->throws(PackageVerificationException::class);

it('rejects an archive whose checksum does not match the manifest', function () {
    [$zipPath, $manifest, $signature] = signedFixture();
    file_put_contents($zipPath, 'tampered-bytes');

    try {
        app(PackageVerifier::class)->verify($zipPath, $manifest, $signature);
    } finally {
        @unlink($zipPath);
    }
})->throws(PackageVerificationException::class);

it('rejects signatures from unknown keys', function () {
    [$zipPath, $manifest, $signature] = signedFixture();
    $manifest['key_id'] = 'unknown-key';

    try {
        app(PackageVerifier::class)->verify($zipPath, $manifest, $signature);
    } finally {
        @unlink($zipPath);
    }
})->throws(PackageVerificationException::class);

it('rejects packages when no signing keys are configured', function () {
    [$zipPath, $manifest, $signature] = signedFixture();
    config(['nebula.package_signing_keys' => []]);

    try {
        app(PackageVerifier::class)->verify($zipPath, $manifest, $signature);
    } finally {
        @unlink($zipPath);
    }
})->throws(PackageVerificationException::class);

it('produces a stable canonical payload regardless of key order', function () {
    $a = ['b' => 1, 'a' => ['d' => 2, 'c' => 3]];
    $b = ['a' => ['c' => 3, 'd' => 2], 'b' => 1];

    expect(PackageSignature::canonicalJson($a))->toBe(PackageSignature::canonicalJson($b));
});
