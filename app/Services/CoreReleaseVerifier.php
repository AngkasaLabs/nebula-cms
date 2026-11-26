<?php

namespace App\Services;

use App\Exceptions\PackageVerificationException;

/**
 * Verifies the signature of a core CMS release archive.
 *
 * Core releases are built by CI and signed offline with the same Ed25519 key
 * that signs extension packages, but they use a dedicated manifest schema
 * (`type: core`, `slug: nebula-cms`) so a package signature can never be
 * replayed as a core update.
 */
class CoreReleaseVerifier
{
    public const SLUG = 'nebula-cms';

    public const TYPE = 'core';

    public function __construct(
        protected PackageVerifier $verifier,
    ) {}

    /**
     * @param  array<string, mixed>  $manifest
     */
    public function verify(string $zipPath, array $manifest, string $signatureBase64, ?string $expectedVersion = null): void
    {
        $this->assertManifest($manifest, $expectedVersion);

        $this->verifier->verifySignedManifest($manifest, $signatureBase64, $manifest['key_id'] ?? null);

        if (! is_file($zipPath)) {
            throw new PackageVerificationException('Update archive not found.');
        }

        $this->verifier->assertChecksum($zipPath, $manifest);
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    protected function assertManifest(array $manifest, ?string $expectedVersion): void
    {
        foreach (['slug', 'type', 'version', 'sha256'] as $field) {
            if (! isset($manifest[$field]) || ! is_string($manifest[$field]) || $manifest[$field] === '') {
                throw new PackageVerificationException("Update manifest is missing required field [{$field}].");
            }
        }

        if ($manifest['slug'] !== self::SLUG) {
            throw new PackageVerificationException('Update manifest is not a NebulaCMS core release.');
        }

        if ($manifest['type'] !== self::TYPE) {
            throw new PackageVerificationException('Update manifest has the wrong package type.');
        }

        if ($expectedVersion !== null && $manifest['version'] !== $expectedVersion) {
            throw new PackageVerificationException(
                "Update manifest version [{$manifest['version']}] does not match the release [{$expectedVersion}]."
            );
        }
    }
}
