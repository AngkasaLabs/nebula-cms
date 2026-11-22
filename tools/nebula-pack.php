#!/usr/bin/env php
<?php

/**
 * nebula-pack — offline packaging and signing tool for NebulaCMS plugins/themes.
 *
 * The private key never leaves your machine. Run this from your development
 * workspace, then upload the generated .zip and .sig.json to the store.
 *
 * Usage:
 *   php tools/nebula-pack.php keygen <key-id>
 *   php tools/nebula-pack.php pack <path-to-package> [--key=PATH] [--out=DIR]
 *   php tools/nebula-pack.php verify <package.zip> <package.sig.json> --public-key=BASE64
 */

const SIGNED_FIELDS = ['slug', 'type', 'version', 'sha256', 'size', 'requires', 'key_id'];

function fail(string $message): never
{
    fwrite(STDERR, "error: {$message}\n");
    exit(1);
}

function defaultKeyDir(): string
{
    $home = getenv('HOME') ?: sys_get_temp_dir();

    return $home.'/.nebula/keys';
}

function parseOptions(array $argv, int $offset): array
{
    $options = [];

    foreach (array_slice($argv, $offset) as $argument) {
        if (! str_starts_with($argument, '--')) {
            continue;
        }

        [$key, $value] = array_pad(explode('=', substr($argument, 2), 2), 2, true);
        $options[$key] = $value;
    }

    return $options;
}

function canonicalJson(array $data): string
{
    $sort = function (array $value) use (&$sort): array {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $sort($item);
            }
        }

        return $value;
    };

    return json_encode(
        $sort($data),
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
    );
}

function payload(array $manifest): array
{
    $payload = [];

    foreach (SIGNED_FIELDS as $field) {
        if (array_key_exists($field, $manifest)) {
            $payload[$field] = $manifest[$field];
        }
    }

    return $payload;
}

function commandKeygen(array $argv): void
{
    $keyId = $argv[2] ?? null;

    if (! is_string($keyId) || ! preg_match('/^[A-Za-z0-9._-]+$/', $keyId)) {
        fail('usage: keygen <key-id>  (letters, numbers, dot, dash, underscore)');
    }

    $dir = defaultKeyDir();

    if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
        fail("cannot create key directory: {$dir}");
    }

    $path = $dir.'/'.$keyId.'.key';

    if (file_exists($path)) {
        fail("key already exists: {$path}");
    }

    $keypair = sodium_crypto_sign_keypair();
    $secret = sodium_crypto_sign_secretkey($keypair);
    $public = sodium_crypto_sign_publickey($keypair);

    file_put_contents($path, base64_encode($secret));
    chmod($path, 0600);

    fwrite(STDOUT, "Private key saved: {$path}\n\n");
    fwrite(STDOUT, "Back this file up and never commit it.\n");
    fwrite(STDOUT, "Public key for config/nebula.php:\n\n");
    fwrite(STDOUT, "NEBULA_PACKAGE_SIGNING_KEYS='{\"{$keyId}\":\"".base64_encode($public)."\"}'\n");
    fwrite(STDOUT, "NEBULA_PACKAGE_SIGNING_KEY_ID={$keyId}\n");
}

function commandPack(array $argv): void
{
    $input = $argv[2] ?? null;

    if (! is_string($input) || ! is_dir($input)) {
        fail('usage: pack <path-to-package> [--key=PATH] [--out=DIR]');
    }

    $options = parseOptions($argv, 3);
    $root = realpath($input);

    $manifestName = file_exists($root.'/plugin.json') ? 'plugin.json' : 'theme.json';
    $type = $manifestName === 'plugin.json' ? 'plugin' : 'theme';

    if (! file_exists($root.'/'.$manifestName)) {
        fail("no plugin.json or theme.json found in {$root}");
    }

    $config = json_decode((string) file_get_contents($root.'/'.$manifestName), true);

    if (! is_array($config) || ! isset($config['name'], $config['slug'], $config['version'])) {
        fail("{$manifestName} must contain name, slug and version");
    }

    if (isset($options['key']) && is_string($options['key'])) {
        $keyPath = $options['key'];
    } elseif (isset($options['key-id']) && is_string($options['key-id'])) {
        $keyPath = defaultKeyDir().'/'.$options['key-id'].'.key';
    } else {
        $availableKeys = glob(defaultKeyDir().'/*.key') ?: [];

        if (count($availableKeys) !== 1) {
            fail('multiple signing keys found; pass --key=PATH or --key-id=ID');
        }

        $keyPath = $availableKeys[0];
    }

    if (! is_file($keyPath)) {
        fail("private key not found: {$keyPath} (run keygen first)");
    }

    $keyId = basename($keyPath, '.key');
    $secret = base64_decode(trim((string) file_get_contents($keyPath)), true);

    if ($secret === false || strlen($secret) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
        fail('invalid private key file');
    }

    $outDir = $options['out'] ?? getcwd().'/dist';

    if (! is_dir($outDir) && ! mkdir($outDir, 0755, true) && ! is_dir($outDir)) {
        fail("cannot create output directory: {$outDir}");
    }

    $base = $config['slug'].'-'.$config['version'];
    $zipPath = rtrim($outDir, '/').'/'.$base.'.zip';

    if (file_exists($zipPath)) {
        unlink($zipPath);
    }

    $zip = new ZipArchive();

    if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
        fail("cannot create archive: {$zipPath}");
    }

    $skipped = ['.git', '.github', '.gitignore', '.gitattributes', '.editorconfig', '.idea', '.vscode', '.fleet', '.nova', '.zed', 'node_modules', 'vendor', '.DS_Store'];

    $addDirectory = function (string $directory, string $prefix) use (&$addDirectory, $zip, $skipped): void {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..' || in_array($entry, $skipped, true)) {
                continue;
            }

            $path = $directory.'/'.$entry;
            $local = $prefix === '' ? $entry : $prefix.'/'.$entry;

            if (is_dir($path)) {
                $addDirectory($path, $local);
            } else {
                $zip->addFile($path, $local);
            }
        }
    };

    $addDirectory($root, '');
    $zip->close();

    $sha256 = hash_file('sha256', $zipPath);
    $size = filesize($zipPath);

    $manifest = [
        'slug' => $config['slug'],
        'type' => $type,
        'version' => $config['version'],
        'sha256' => $sha256,
        'size' => $size,
        'requires' => $config['requires'] ?? [],
        'key_id' => $keyId,
    ];

    $signature = sodium_crypto_sign_detached(canonicalJson(payload($manifest)), $secret);

    $manifest['signature'] = base64_encode($signature);
    $manifest['name'] = $config['name'];
    $manifest['signed_at'] = gmdate('c');

    $sigPath = rtrim($outDir, '/').'/'.$base.'.sig.json';

    file_put_contents($sigPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

    fwrite(STDOUT, "Package:  {$zipPath}\n");
    fwrite(STDOUT, "Manifest: {$sigPath}\n");
    fwrite(STDOUT, "SHA-256:  {$sha256}\n");
    fwrite(STDOUT, "Size:     {$size} bytes\n");
    fwrite(STDOUT, "Key:      {$keyId}\n");
}

function commandVerify(array $argv): void
{
    $zipPath = $argv[2] ?? null;
    $sigPath = $argv[3] ?? null;
    $options = parseOptions($argv, 4);

    if (! is_string($zipPath) || ! is_string($sigPath) || ! is_file($zipPath) || ! is_file($sigPath)) {
        fail('usage: verify <package.zip> <package.sig.json> --public-key=BASE64');
    }

    $manifest = json_decode((string) file_get_contents($sigPath), true);

    if (! is_array($manifest) || ! isset($manifest['signature'])) {
        fail('invalid signature manifest');
    }

    $publicKey = $options['public-key'] ?? null;

    if (! is_string($publicKey) || $publicKey === true) {
        $publicKey = getenv('NEBULA_PUBLIC_KEY') ?: null;
    }

    if (! is_string($publicKey) || $publicKey === '') {
        fail('provide --public-key=BASE64 or NEBULA_PUBLIC_KEY');
    }

    $decodedPublic = base64_decode($publicKey, true);
    $signature = base64_decode((string) $manifest['signature'], true);

    if ($decodedPublic === false || strlen($decodedPublic) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
        fail('invalid public key');
    }

    if ($signature === false || ! sodium_crypto_sign_verify_detached(
        $signature,
        canonicalJson(payload($manifest)),
        $decodedPublic
    )) {
        fail('signature is invalid');
    }

    if (! hash_equals((string) ($manifest['sha256'] ?? ''), (string) hash_file('sha256', $zipPath))) {
        fail('checksum does not match');
    }

    if (isset($manifest['size']) && (int) $manifest['size'] !== (int) filesize($zipPath)) {
        fail('size does not match');
    }

    fwrite(STDOUT, "OK: {$manifest['slug']} {$manifest['version']} ({$manifest['type']})\n");
}

$command = $argv[1] ?? null;

match ($command) {
    'keygen' => commandKeygen($argv),
    'pack' => commandPack($argv),
    'verify' => commandVerify($argv),
    default => fail('usage: nebula-pack <keygen|pack|verify> ...'),
};
