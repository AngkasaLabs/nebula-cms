<?php

namespace App\Services;

use App\Exceptions\StoreException;
use App\Models\Plugin;
use App\Models\Theme;
use Illuminate\Support\Facades\Cache;

/**
 * Tracks store updates for the plugins and themes installed on this site.
 */
class StoreUpdates
{
    public const CACHE_KEY = 'store:updates';

    public const CACHE_HOURS = 6;

    public function __construct(
        protected StoreClient $client,
    ) {}

    /**
     * @return array<int, array{slug: string, type: string, version: string}>
     */
    public function installed(): array
    {
        $plugins = Plugin::query()
            ->get(['slug', 'version'])
            ->map(fn (Plugin $plugin) => [
                'slug' => $plugin->slug,
                'type' => 'plugin',
                'version' => (string) $plugin->version,
            ]);

        $themes = Theme::query()
            ->get(['slug', 'version'])
            ->map(fn (Theme $theme) => [
                'slug' => $theme->slug,
                'type' => 'theme',
                'version' => (string) $theme->version,
            ]);

        return $plugins
            ->concat($themes)
            ->unique(fn (array $item) => $item['type'].':'.$item['slug'])
            ->values()
            ->all();
    }

    /**
     * @return array<string, string> "type:slug" => installed version
     */
    public function installedMap(): array
    {
        $map = [];

        foreach ($this->installed() as $item) {
            $map[$item['type'].':'.$item['slug']] = $item['version'];
        }

        return $map;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function refresh(): array
    {
        if (! $this->client->enabled()) {
            $this->forget();

            return [];
        }

        try {
            $items = $this->client->updates($this->installed());
        } catch (StoreException $e) {
            report($e);

            return [];
        }

        Cache::put(self::CACHE_KEY, [
            'items' => $items,
            'checked_at' => now()->toIso8601String(),
        ], now()->addHours(self::CACHE_HOURS));

        return $items;
    }

    /**
     * @return array{items: array<int, array<string, mixed>>, checked_at: string}|null
     */
    public function cached(): ?array
    {
        $cached = Cache::get(self::CACHE_KEY);

        return is_array($cached) ? $cached : null;
    }

    /**
     * @return array<string, array<string, mixed>> "type:slug" => update payload
     */
    public function map(): array
    {
        $map = [];

        foreach ($this->cached()['items'] ?? [] as $item) {
            if (is_array($item) && isset($item['slug'], $item['type'])) {
                $map[$item['type'].':'.$item['slug']] = $item;
            }
        }

        return $map;
    }

    /**
     * @return array{count: int, items: array<int, array<string, mixed>>, checked_at: string|null}
     */
    public function summary(): array
    {
        $cached = $this->cached();
        $items = [];

        foreach ($cached['items'] ?? [] as $item) {
            if (! is_array($item) || ! isset($item['slug'], $item['type'])) {
                continue;
            }

            $items[] = [
                'slug' => $item['slug'],
                'type' => $item['type'],
                'latest' => $item['latest'] ?? null,
            ];
        }

        return [
            'count' => count($items),
            'items' => $items,
            'checked_at' => $cached['checked_at'] ?? null,
        ];
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
