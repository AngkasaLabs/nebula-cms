<?php

namespace App\Support;

/**
 * Normalises admin navigation items contributed by plugins.
 *
 * Plugins only pick a section; the labels and ordering of sections belong to
 * the core so the sidebar stays predictable as more extensions are installed.
 * Unknown sections fall back to "Extensions" instead of leaking into the
 * system area.
 */
class AdminNavigation
{
    /**
     * Section key => label and render order. `main` has no label.
     *
     * @var array<string, array{label: string|null, order: int}>
     */
    public const SECTIONS = [
        'main' => ['label' => null, 'order' => 10],
        'content' => ['label' => 'Content Management', 'order' => 20],
        'commerce' => ['label' => 'Commerce', 'order' => 30],
        'tools' => ['label' => 'Tools', 'order' => 40],
        'system' => ['label' => 'System', 'order' => 80],
        'extensions' => ['label' => 'Extensions', 'order' => 90],
    ];

    public const FALLBACK_SECTION = 'extensions';

    /**
     * Group values accepted from older plugin manifests.
     *
     * @var array<string, string>
     */
    public const LEGACY_SECTIONS = [
        'admin' => 'extensions',
    ];

    public const DEFAULT_ICON = 'Puzzle';

    /**
     * Icon names the admin sidebar can render. Must stay in sync with the
     * iconMap in resources/js/components/app-sidebar.tsx (covered by a test).
     *
     * @var array<int, string>
     */
    public const ICONS = [
        'AlignJustify',
        'BarChart3',
        'BookOpen',
        'Boxes',
        'Calendar',
        'Code',
        'CreditCard',
        'Database',
        'Download',
        'File',
        'FileText',
        'Flag',
        'Folder',
        'Globe',
        'Image',
        'Languages',
        'LayoutGrid',
        'LifeBuoy',
        'Mail',
        'Megaphone',
        'MessageSquare',
        'Package',
        'Paintbrush',
        'Puzzle',
        'Receipt',
        'Route',
        'ScrollText',
        'Settings',
        'Shield',
        'ShoppingCart',
        'Store',
        'Tag',
        'Ticket',
        'Truck',
        'Users',
        'Wrench',
        'Zap',
    ];

    public const DEFAULT_ORDER = 100;

    /**
     * Ordered section metadata for the sidebar.
     *
     * @return array<int, array{key: string, label: string|null, order: int}>
     */
    public static function sections(): array
    {
        $sections = [];

        foreach (self::SECTIONS as $key => $meta) {
            $sections[] = ['key' => $key, 'label' => $meta['label'], 'order' => $meta['order']];
        }

        usort($sections, fn (array $a, array $b) => $a['order'] <=> $b['order']);

        return $sections;
    }

    /**
     * Resolve a manifest group value to a known section key.
     */
    public static function sectionFor(mixed $group): string
    {
        if (! is_string($group) || trim($group) === '') {
            return self::FALLBACK_SECTION;
        }

        $group = strtolower(trim($group));

        if (isset(self::SECTIONS[$group])) {
            return $group;
        }

        return self::LEGACY_SECTIONS[$group] ?? self::FALLBACK_SECTION;
    }

    /**
     * @param  array<int, mixed>  $items
     * @return array<int, array<string, mixed>>
     */
    public static function normalizeItems(array $items, ?object $user = null): array
    {
        $normalized = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $entry = self::normalizeItem($item);

            if ($entry === null) {
                continue;
            }

            if (! self::permits($entry['permission'], $user)) {
                continue;
            }

            $normalized[] = $entry;
        }

        usort($normalized, function (array $a, array $b): int {
            $sectionOrder = self::SECTIONS[$a['group']]['order'] <=> self::SECTIONS[$b['group']]['order'];

            if ($sectionOrder !== 0) {
                return $sectionOrder;
            }

            $itemOrder = $a['order'] <=> $b['order'];

            if ($itemOrder !== 0) {
                return $itemOrder;
            }

            return strcasecmp($a['title'], $b['title']);
        });

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>|null
     */
    public static function normalizeItem(array $item): ?array
    {
        $title = $item['title'] ?? null;
        $href = $item['href'] ?? null;

        if (! is_string($title) || trim($title) === '' || ! is_string($href) || trim($href) === '') {
            return null;
        }

        $children = [];
        foreach ($item['items'] ?? [] as $child) {
            if (! is_array($child)) {
                continue;
            }

            $childTitle = $child['title'] ?? null;
            $childHref = $child['href'] ?? null;

            if (! is_string($childTitle) || trim($childTitle) === '' || ! is_string($childHref) || trim($childHref) === '') {
                continue;
            }

            $children[] = [
                'title' => $childTitle,
                'href' => $childHref,
            ];
        }

        $matchPaths = array_values(array_filter(
            is_array($item['match_paths'] ?? null) ? $item['match_paths'] : [],
            fn ($path) => is_string($path) && $path !== ''
        ));

        $order = $item['order'] ?? null;
        $permission = $item['permission'] ?? null;

        return [
            'title' => $title,
            'href' => $href,
            'group' => self::sectionFor($item['group'] ?? null),
            'badge' => is_string($item['badge'] ?? null) || is_bool($item['badge'] ?? null) ? $item['badge'] : null,
            'icon' => is_string($item['icon'] ?? null) ? $item['icon'] : null,
            'items' => $children,
            'match_paths' => $matchPaths,
            'order' => is_numeric($order) ? (int) $order : self::DEFAULT_ORDER,
            'permission' => is_string($permission) && $permission !== '' ? $permission : null,
        ];
    }

    protected static function permits(?string $permission, ?object $user): bool
    {
        if ($permission === null) {
            return true;
        }

        if ($user === null || ! method_exists($user, 'can')) {
            return false;
        }

        return (bool) $user->can($permission);
    }
}
