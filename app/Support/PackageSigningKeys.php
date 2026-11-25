<?php

namespace App\Support;

/**
 * Resolves the Ed25519 public keys this installation trusts for plugin and
 * theme packages.
 *
 * The keys declared in `config/nebula.php` are the trust anchor every install
 * ships with. Keys from `NEBULA_PACKAGE_SIGNING_KEYS` are added on top of them
 * (a key id that already exists replaces the built-in value), so a site can
 * trust its own signing key without losing official packages and without
 * pinning a possibly stale copy of the official key in its `.env`.
 *
 * Installations that want to trust only their own keys can set
 * `NEBULA_PACKAGE_SIGNING_KEYS_REPLACE=true`.
 */
class PackageSigningKeys
{
    /**
     * @param  array<string, string>  $defaults
     * @return array<string, string>
     */
    public static function resolve(array $defaults, mixed $raw, bool $replace = false): array
    {
        $keys = self::normalize($raw);

        if ($replace) {
            return $keys;
        }

        return array_replace($defaults, $keys);
    }

    /**
     * @return array<string, string>
     */
    protected static function normalize(mixed $raw): array
    {
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return [];
        }

        $keys = [];

        foreach ($decoded as $id => $key) {
            if (! is_string($id) || $id === '' || ! is_string($key) || $key === '') {
                continue;
            }

            $keys[$id] = $key;
        }

        return $keys;
    }
}
