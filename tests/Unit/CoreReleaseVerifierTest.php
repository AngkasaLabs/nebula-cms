<?php

use App\Exceptions\PackageVerificationException;
use App\Services\CoreReleaseVerifier;
use App\Support\PackageSignature;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    $this->keypair = sodium_crypto_sign_keypair();

    config([
        'nebula.package_signing_keys' => [
            'test-core-key' => base64_encode(sodium_crypto_sign_publickey($this->keypair)),
        ],
        'nebula.package_signing_key_id' => 'test-core-key',
    ]);
});

function core_manifest(string $zipPath, array $overrides = []): array
{
    $bytes = (string) file_get_contents($zipPath);

    return array_merge([
        'slug' => 'nebula-cms',
        'type' => 'core',
        'version' => '1.13.0',
        'sha256' => hash('sha256', $bytes),
        'size' => strlen($bytes),
        'key_id' => 'test-core-key',
    ], $overrides);
}

function core_signature(array $manifest): string
{
    return base64_encode(sodium_crypto_sign_detached(
        PackageSignature::canonicalJson(PackageSignature::payload($manifest)),
        sodium_crypto_sign_secretkey($GLOBALS['core_test_keypair'])
    ));
}

beforeEach(function () {
    $GLOBALS['core_test_keypair'] = $this->keypair;
});

afterEach(function () {
    unset($GLOBALS['core_test_keypair']);
});

it('accepts a signed core release', function () {
    $zip = sys_get_temp_dir().'/core-'.uniqid().'.zip';
    writeValidZip($zip);

    try {
        $manifest = core_manifest($zip);

        app(CoreReleaseVerifier::class)->verify($zip, $manifest, core_signature($manifest), '1.13.0');

        expect(true)->toBeTrue();
    } finally {
        @unlink($zip);
    }
});

it('rejects a tampered core archive', function () {
    $zip = sys_get_temp_dir().'/core-'.uniqid().'.zip';
    writeValidZip($zip);

    try {
        $manifest = core_manifest($zip);
        $signature = core_signature($manifest);

        file_put_contents($zip, 'tampered');

        app(CoreReleaseVerifier::class)->verify($zip, $manifest, $signature, '1.13.0');
    } finally {
        @unlink($zip);
    }
})->throws(PackageVerificationException::class, 'checksum');

it('rejects a manifest that is not a core release', function () {
    $zip = sys_get_temp_dir().'/core-'.uniqid().'.zip';
    writeValidZip($zip);

    try {
        $manifest = core_manifest($zip, ['slug' => 'redirect-manager', 'type' => 'plugin']);

        app(CoreReleaseVerifier::class)->verify($zip, $manifest, core_signature($manifest), '1.13.0');
    } finally {
        @unlink($zip);
    }
})->throws(PackageVerificationException::class, 'not a NebulaCMS core release');

it('rejects a release for a different version', function () {
    $zip = sys_get_temp_dir().'/core-'.uniqid().'.zip';
    writeValidZip($zip);

    try {
        $manifest = core_manifest($zip);

        app(CoreReleaseVerifier::class)->verify($zip, $manifest, core_signature($manifest), '1.13.1');
    } finally {
        @unlink($zip);
    }
})->throws(PackageVerificationException::class, 'does not match the release');

it('rejects an unknown signing key', function () {
    $zip = sys_get_temp_dir().'/core-'.uniqid().'.zip';
    writeValidZip($zip);

    try {
        $manifest = core_manifest($zip, ['key_id' => 'unknown-key']);

        app(CoreReleaseVerifier::class)->verify($zip, $manifest, core_signature($manifest), '1.13.0');
    } finally {
        @unlink($zip);
    }
})->throws(PackageVerificationException::class, 'Unknown package signing key');

it('rejects an empty signature', function () {
    $zip = sys_get_temp_dir().'/core-'.uniqid().'.zip';
    writeValidZip($zip);

    try {
        app(CoreReleaseVerifier::class)->verify($zip, core_manifest($zip), '', '1.13.0');
    } finally {
        @unlink($zip);
    }
})->throws(PackageVerificationException::class, 'invalid length');
