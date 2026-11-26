<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PageRevision extends Model
{
    protected $fillable = [
        'page_id',
        'user_id',
        'title',
        'content',
        'featured_image',
        'meta_description',
        'meta_keywords',
        'revision_number',
    ];

    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function getNextRevisionNumber(int $pageId): int
    {
        $maxRevision = static::where('page_id', $pageId)->max('revision_number');

        return ($maxRevision ?? 0) + 1;
    }
}
