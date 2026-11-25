<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Kalnoy\Nestedset\NodeTrait;

class Category extends Model
{
    use HasFactory, NodeTrait;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'color',
        'parent_id',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($category) {
            $category->slug = $category->slug ?? Str::slug($category->name);
        });
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }
}
