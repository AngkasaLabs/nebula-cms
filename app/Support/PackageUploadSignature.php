<?php

namespace App\Support;

use App\Exceptions\UploadSignatureException;
use Illuminate\Http\UploadedFile;

/**
 * Parses the `.sig.json` manifest uploaded next to an admin ZIP upload.
 *
 * The signature is the trust anchor for the upload: it must name the package
 * type of the endpoint and carry a signature that PackageVerifier can check
 * before the installer writes anything to disk.
 */
class PackageUploadSignature
{
    /**
     * @return array<string, mixed>
     */
    public static function parse(?UploadedFile $file): array
    {
        if (! $file instanceof UploadedFile) {
            throw new UploadSignatureException('A signature file (.sig.json) is required.');
        }

        try {
            $contents = $file->get();
        } catch (\Throwable $e) {
            throw new UploadSignatureException('Signature file could not be read.');
        }

        $decoded = json_decode($contents, true);

        if (! is_array($decoded)) {
            throw new UploadSignatureException('Signature file is not valid JSON.');
        }

        if (! isset($decoded['signature']) || ! is_string($decoded['signature']) || $decoded['signature'] === '') {
            throw new UploadSignatureException('Signature file is missing the "signature" value.');
        }

        if (! isset($decoded['type']) || ! in_array($decoded['type'], ['plugin', 'theme'], true)) {
            throw new UploadSignatureException('Signature file must declare type "plugin" or "theme".');
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $signature
     */
    public static function assertType(array $signature, string $expectedType): void
    {
        if (($signature['type'] ?? null) !== $expectedType) {
            throw new UploadSignatureException(
                "This signature belongs to a {$signature['type']} package, not a {$expectedType} package."
            );
        }
    }
}
