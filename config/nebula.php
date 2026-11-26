<?php

use App\Support\PackageSigningKeys;

return [

    /*
    |--------------------------------------------------------------------------
    | CMS Version
    |--------------------------------------------------------------------------
    |
    | The current version of NebulaCMS. This is used by the self-updater
    | to compare against the latest GitHub release.
    |
    */
    'version' => '1.13.2',

    /*
    |--------------------------------------------------------------------------
    | GitHub Repository
    |--------------------------------------------------------------------------
    |
    | The GitHub repository used to check for updates. Format: owner/repo
    |
    */
    'github_repo' => 'AngkasaLabs/nebula-cms',

    /*
    |--------------------------------------------------------------------------
    | Update Backup Retention
    |--------------------------------------------------------------------------
    |
    | How many pre-update backup archives to keep on disk. Older archives are
    | removed automatically after a new backup is created, or when an admin
    | triggers a manual cleanup from Settings → System Update.
    |
    */
    'backup_retention' => (int) env('NEBULA_BACKUP_RETENTION', 5),

    /*
    |--------------------------------------------------------------------------
    | Package Backup Retention
    |--------------------------------------------------------------------------
    |
    | How many plugin/theme folder snapshots to keep per package. A snapshot is
    | created before every store update so failures can be rolled back.
    |
    */
    'package_backup_retention' => (int) env('NEBULA_PACKAGE_BACKUP_RETENTION', 5),

    /*
    |--------------------------------------------------------------------------
    | Package Store
    |--------------------------------------------------------------------------
    |
    | URL of the NebulaCMS package registry. Can be overridden from
    | Settings → General without editing this file.
    |
    */
    'store_url' => env('NEBULA_STORE_URL', 'https://nebula.angkasalabs.com'),

    'store_enabled' => filter_var(env('NEBULA_STORE_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    'store_timeout' => (int) env('NEBULA_STORE_TIMEOUT', 15),

    /*
    |--------------------------------------------------------------------------
    | Content Search
    |--------------------------------------------------------------------------
    |
    | Full-text indexes power post/page search on MySQL, MariaDB and PostgreSQL.
    | Disable to force the LIKE fallback, for example when the index cannot be
    | created on a restricted shared host.
    |
    */
    'content_search_fulltext' => filter_var(env('NEBULA_CONTENT_SEARCH_FULLTEXT', true), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Package Signing Keys
    |--------------------------------------------------------------------------
    |
    | Ed25519 public keys used to verify plugin/theme packages from the store
    | and the admin upload form. Keys are stored as a JSON map of
    | key_id => base64 public key.
    |
    | The built-in keys below are the trust anchor of every install. Keys from
    | NEBULA_PACKAGE_SIGNING_KEYS are added on top of them, so you can trust
    | your own team's signing key without copying the official key into .env.
    | Set NEBULA_PACKAGE_SIGNING_KEYS_REPLACE=true to trust only the env keys.
    |
    | The matching private key must never be placed on the server; packages are
    | signed offline with tools/nebula-pack.php.
    |
    */
    'package_signing_keys' => PackageSigningKeys::resolve(
        ['official' => '34BVMN/vudFGIXCtDE7HCfmt7LpTeFQZCcLVtC+qb2w='],
        env('NEBULA_PACKAGE_SIGNING_KEYS'),
        filter_var(env('NEBULA_PACKAGE_SIGNING_KEYS_REPLACE', false), FILTER_VALIDATE_BOOL),
    ),

    'package_signing_key_id' => env('NEBULA_PACKAGE_SIGNING_KEY_ID', 'official'),

];
