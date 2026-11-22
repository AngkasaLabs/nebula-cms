<?php

namespace App\Support;

/**
 * Canonical form of a package manifest for signature verification.
 *
 * Both the offline signing tool and the CMS must produce the exact same bytes,
 * so associative arrays are key-sorted recursively while list order is kept.
 */
class PackageSignature
{
    /**
     * Fields covered by the signature. Anything else in the manifest is
     * metadata and is not trusted for integrity decisions.
     */
    public const SIGNED_FIELDS = [
        'slug',
        'type',
        'version',
        'sha256',
        'size',
        'requires',
        'key_id',
    ];

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    public static function payload(array $manifest): array
    {
        $payload = [];

        foreach (self::SIGNED_FIELDS as $field) {
            if (array_key_exists($field, $manifest)) {
                $payload[$field] = $manifest[$field];
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function canonicalJson(array $data): string
    {
        return json_encode(
            self::sortKeys($data),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );
    }

    /**
     * @param  array<int|string, mixed>  $data
     * @return array<int|string, mixed>
     */
    protected static function sortKeys(array $data): array
    {
        if (! array_is_list($data)) {
            ksort($data, SORT_STRING);
        }

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = self::sortKeys($value);
            }
        }

        return $data;
    }
}
