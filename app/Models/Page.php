<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Auth;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Page extends Model
{
    use HasFactory;
    use LogsActivity;

    protected $fillable = [
        'title',
        'slug',
        'content',
        'featured_image',
        'meta_description',
        'meta_keywords',
        'status',
        'published_at',
        'order',
        'user_id',
    ];

    protected $casts = [
        'published_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<PageRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(PageRevision::class)->orderBy('revision_number', 'desc');
    }

    /**
     * Create a revision snapshot of the current page state.
     */
    public function createRevision(?int $userId = null): PageRevision
    {
        return $this->revisions()->create([
            'user_id' => $userId ?? Auth::id(),
            'title' => $this->title,
            'content' => $this->content,
            'featured_image' => $this->featured_image,
            'meta_description' => $this->meta_description,
            'meta_keywords' => $this->meta_keywords,
            'revision_number' => PageRevision::getNextRevisionNumber($this->id),
        ]);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'published')
            ->whereNotNull('published_at');
    }

    public function scopeScheduled(Builder $query): Builder
    {
        return $query->where('status', 'scheduled')
            ->where('published_at', '>', now());
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['title', 'slug', 'status', 'user_id'])
            ->logOnlyDirty()
            ->submitEmptyLogs(false)
            ->useLogName('page');
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($page) {
            if (empty($page->slug)) {
                $page->slug = str($page->title)->slug();
            }
        });

        static::saving(function ($page) {
            if ($page->status === 'published' && (! $page->published_at || $page->published_at > now())) {
                $page->published_at = now();
            }
        });
    }
}
