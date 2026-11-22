<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PluginMigration extends Model
{
    protected $fillable = [
        'plugin',
        'migration',
        'batch',
    ];

    protected $casts = [
        'batch' => 'integer',
    ];
}
