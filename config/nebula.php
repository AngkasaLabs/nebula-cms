<?php

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
    'version' => '1.8.1',

    /*
    |--------------------------------------------------------------------------
    | GitHub Repository
    |--------------------------------------------------------------------------
    |
    | The GitHub repository used to check for updates. Format: owner/repo
    |
    */
    'github_repo' => 'AngkasaLabs/NebulaCMS',

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
    | Package Signing Keys
    |--------------------------------------------------------------------------
    |
    | Ed25519 public keys used to verify plugin/theme packages downloaded from
    | the store. Keys are stored as a JSON map of key_id => base64 public key,
    | which allows key rotation without a code change. The matching private key
    | must never be placed on the server; packages are signed offline.
    |
    */
    'package_signing_keys' => json_decode((string) env('NEBULA_PACKAGE_SIGNING_KEYS', '{"official":"34BVMN/vudFGIXCtDE7HCfmt7LpTeFQZCcLVtC+qb2w="}'), true) ?: [],

    'package_signing_key_id' => env('NEBULA_PACKAGE_SIGNING_KEY_ID', 'official'),

];
