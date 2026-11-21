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
    'version' => '1.8.0',

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

];
