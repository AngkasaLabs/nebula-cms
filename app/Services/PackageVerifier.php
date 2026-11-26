<?php

namespace App\Services;

use App\Exceptions\PackageVerificationException;
use App\Support\PackageSignature;

/**
 * Verifies downloaded plugin/theme packages before installation.
 *
 * A package is trusted only when its manifest carries a valid Ed25519
 * signature from a configured publisher key AND the archive's SHA-256 matches
 * the signed manifest.
 */
class PackageVerifier
{
    /**
     * @param  array<string, mixed>  $manifest
     */
    public function verify(string $zipPath, array $manifest, string $signatureBase64, ?string $keyId = null): void
    {
        $this->verifyManifest($manifest, $signatureBase64, $keyId);

        if (! is_file($zipPath)) {
            throw new PackageVerificationException('Package archive not found.');
        }

        $this->assertChecksum($zipPath, $manifest);
    }

    /**
     * Verifies the signature without touching an archive. Useful on the store
     * server before accepting a package.
     *
     * @param  array<string, mixed>  $manifest
     */
    public function verifyManifest(array $manifest, string $signatureBase64, ?string $keyId = null): void
    {
        $this->assertManifestComplete($manifest);
        $this->verifySignedManifest($manifest, $signatureBase64, $keyId);
    }

    /**
     * Verifies the Ed25519 signature over the signed manifest fields.
     *
     * Unlike {@see verifyManifest()} this does not enforce the plugin/theme
     * package schema, so core releases can reuse the same trust anchor.
     *
     * @param  array<string, mixed>  $manifest
     */
    public function verifySignedManifest(array $manifest, string $signatureBase64, ?string $keyId = null): void
    {
        $publicKey = $this->resolvePublicKey($keyId ?? ($manifest['key_id'] ?? null));
        $signature = $this->decodeBase64($signatureBase64, 'signature');

        if (strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new PackageVerificationException('Signature has an invalid length.');
        }

        $payload = PackageSignature::canonicalJson(PackageSignature::payload($manifest));

        if (! sodium_crypto_sign_verify_detached($signature, $payload, $publicKey)) {
            throw new PackageVerificationException('Package signature is invalid.');
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    public function assertChecksum(string $zipPath, array $manifest): void
    {
        $expected = $manifest['sha256'] ?? null;

        if (! is_string($expected) || ! preg_match('/^[a-f0-9]{64}$/i', $expected)) {
            throw new PackageVerificationException('Manifest is missing a valid sha256 checksum.');
        }

        $actual = hash_file('sha256', $zipPath);

        if ($actual === false || ! hash_equals(strtolower($expected), strtolower($actual))) {
            throw new PackageVerificationException('Package checksum does not match the signed manifest.');
        }

        if (isset($manifest['size']) && is_numeric($manifest['size'])) {
            $size = filesize($zipPath);

            if ($size === false || (int) $manifest['size'] !== (int) $size) {
                throw new PackageVerificationException('Package size does not match the signed manifest.');
            }
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    protected function assertManifestComplete(array $manifest): void
    {
        foreach (['slug', 'type', 'version', 'sha256'] as $field) {
            if (! isset($manifest[$field]) || ! is_string($manifest[$field]) || $manifest[$field] === '') {
                throw new PackageVerificationException("Manifest is missing required field [{$field}].");
            }
        }

        if (! in_array($manifest['type'], ['plugin', 'theme'], true)) {
            throw new PackageVerificationException('Manifest type must be "plugin" or "theme".');
        }

        if (! preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $manifest['slug'])) {
            throw new PackageVerificationException('Manifest slug is invalid.');
        }
    }

    protected function resolvePublicKey(?string $keyId): string
    {
        $keys = config('nebula.package_signing_keys', []);

        if (! is_array($keys) || $keys === []) {
            throw new PackageVerificationException('No package signing keys are configured.');
        }

        if ($keyId === null || $keyId === '') {
            $keyId = config('nebula.package_signing_key_id');

            if (($keyId === null || $keyId === '') && count($keys) === 1) {
                $keyId = array_key_first($keys);
            }
        }

        if (! is_string($keyId) || ! isset($keys[$keyId])) {
            throw new PackageVerificationException('Unknown package signing key.');
        }

        $publicKey = $this->decodeBase64((string) $keys[$keyId], 'public key');

        if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new PackageVerificationException('Package signing public key has an invalid length.');
        }

        return $publicKey;
    }

    protected function decodeBase64(string $value, string $label): string
    {
        $decoded = base64_decode($value, true);

        if ($decoded === false) {
            throw new PackageVerificationException("Package {$label} is not valid base64.");
        }

        return $decoded;
    }
}
