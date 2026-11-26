<?php

use App\Exceptions\PackageVerificationException;
use App\Services\UpdateService;
use App\Support\PackageSignature;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
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

function update_manifest(string $zipPath, array $overrides = []): array
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

function update_signature(array $manifest): string
{
    return base64_encode(sodium_crypto_sign_detached(
        PackageSignature::canonicalJson(PackageSignature::payload($manifest)),
        sodium_crypto_sign_secretkey($GLOBALS['update_test_keypair'])
    ));
}

beforeEach(function () {
    $GLOBALS['update_test_keypair'] = $this->keypair;
});

afterEach(function () {
    unset($GLOBALS['update_test_keypair']);
});

it('returns the signature asset url when checking for updates', function () {
    Config::set('nebula.version', '1.12.0');
    Config::set('nebula.github_repo', 'test/repo');

    Http::fake([
        'api.github.com/repos/test/repo/releases/latest' => Http::response([
            'tag_name' => 'v1.13.0',
            'html_url' => 'https://github.com/test/repo/releases/tag/v1.13.0',
            'body' => '',
            'published_at' => '2026-01-01T00:00:00Z',
            'assets' => [
                ['name' => 'nebulacms-v1.13.0.zip', 'browser_download_url' => 'https://github.com/dl.zip'],
                ['name' => 'nebulacms-v1.13.0.zip.sig.json', 'browser_download_url' => 'https://github.com/dl.zip.sig.json'],
            ],
        ], 200),
    ]);

    $result = app(UpdateService::class)->checkForUpdate();

    expect($result['available'])->toBeTrue()
        ->and($result['download_url'])->toBe('https://github.com/dl.zip')
        ->and($result['signature_url'])->toBe('https://github.com/dl.zip.sig.json');
});

it('rejects unsigned updates for versions that require a signature', function () {
    $zip = sys_get_temp_dir().'/update-'.uniqid().'.zip';
    writeValidZip($zip);

    try {
        app(UpdateService::class)->verifyReleaseArchive($zip, null, '1.13.0');
    } finally {
        @unlink($zip);
    }
})->throws(PackageVerificationException::class, 'not signed');

it('rejects unsigned updates when the target version is unknown', function () {
    $zip = sys_get_temp_dir().'/update-'.uniqid().'.zip';
    writeValidZip($zip);

    try {
        app(UpdateService::class)->verifyReleaseArchive($zip, null, null);
    } finally {
        @unlink($zip);
    }
})->throws(PackageVerificationException::class, 'not signed');

it('allows legacy unsigned releases below the signing cutoff', function () {
    $zip = sys_get_temp_dir().'/update-'.uniqid().'.zip';
    writeValidZip($zip);

    try {
        app(UpdateService::class)->verifyReleaseArchive($zip, null, '1.12.1');

        expect(true)->toBeTrue();
    } finally {
        @unlink($zip);
    }
});

it('accepts a signed release archive', function () {
    $zip = sys_get_temp_dir().'/update-'.uniqid().'.zip';
    writeValidZip($zip);

    try {
        $manifest = update_manifest($zip);
        $manifest['signature'] = update_signature($manifest);

        app(UpdateService::class)->verifyReleaseArchive($zip, $manifest, '1.13.0');

        expect(true)->toBeTrue();
    } finally {
        @unlink($zip);
    }
});

it('rejects a signed release for another version', function () {
    $zip = sys_get_temp_dir().'/update-'.uniqid().'.zip';
    writeValidZip($zip);

    try {
        $manifest = update_manifest($zip);
        $manifest['signature'] = update_signature($manifest);

        app(UpdateService::class)->verifyReleaseArchive($zip, $manifest, '1.14.0');
    } finally {
        @unlink($zip);
    }
})->throws(PackageVerificationException::class, 'does not match the release');

it('refuses to extract an unsigned update before touching the filesystem', function () {
    $zip = sys_get_temp_dir().'/update-'.uniqid().'.zip';
    writeValidZip($zip);

    Http::fake([
        'example.com/release.zip' => Http::response((string) file_get_contents($zip), 200),
    ]);

    try {
        $result = app(UpdateService::class)->applyUpdate('https://example.com/release.zip', null, '1.13.0');

        expect($result['success'])->toBeFalse()
            ->and($result['message'])->toContain('not signed');
    } finally {
        @unlink($zip);
    }
});

it('fails when the signature asset cannot be downloaded', function () {
    $zip = sys_get_temp_dir().'/update-'.uniqid().'.zip';
    writeValidZip($zip);

    Http::fake([
        'example.com/release.zip' => Http::response((string) file_get_contents($zip), 200),
        'example.com/release.zip.sig.json' => Http::response('nope', 500),
    ]);

    try {
        $result = app(UpdateService::class)->applyUpdate(
            'https://example.com/release.zip',
            'https://example.com/release.zip.sig.json',
            '1.13.0',
        );

        expect($result['success'])->toBeFalse()
            ->and($result['message'])->toContain('Could not download the update signature');
    } finally {
        @unlink($zip);
    }
});
