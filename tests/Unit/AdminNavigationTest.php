<?php

use App\Support\AdminNavigation;
use Tests\TestCase;

uses(TestCase::class);

it('resolves known, legacy and unknown sections', function () {
    expect(AdminNavigation::sectionFor('main'))->toBe('main')
        ->and(AdminNavigation::sectionFor('content'))->toBe('content')
        ->and(AdminNavigation::sectionFor('Commerce'))->toBe('commerce')
        ->and(AdminNavigation::sectionFor(' tools '))->toBe('tools')
        ->and(AdminNavigation::sectionFor('admin'))->toBe('extensions')
        ->and(AdminNavigation::sectionFor('some-plugin-group'))->toBe('extensions')
        ->and(AdminNavigation::sectionFor(null))->toBe('extensions')
        ->and(AdminNavigation::sectionFor(''))->toBe('extensions');
});

it('orders sections for the sidebar', function () {
    expect(array_column(AdminNavigation::sections(), 'key'))->toBe([
        'main',
        'content',
        'commerce',
        'tools',
        'system',
        'extensions',
    ]);
});

it('normalises items and maps legacy groups', function () {
    $items = AdminNavigation::normalizeItems([
        [
            'title' => 'Redirects',
            'href' => '/admin/redirects',
            'group' => 'admin',
            'icon' => 'Route',
            'match_paths' => ['/admin/redirects'],
        ],
        [
            'title' => 'Changelog',
            'href' => '/admin/changelog',
            'group' => 'content',
            'items' => [
                ['title' => 'Releases', 'href' => '/admin/changelog/releases'],
                ['title' => '', 'href' => '/admin/changelog/invalid'],
            ],
        ],
    ]);

    expect($items)->toHaveCount(2)
        ->and($items[0]['group'])->toBe('content')
        ->and($items[0]['items'])->toBe([['title' => 'Releases', 'href' => '/admin/changelog/releases']])
        ->and($items[1]['group'])->toBe('extensions')
        ->and($items[1]['order'])->toBe(AdminNavigation::DEFAULT_ORDER)
        ->and($items[1]['permission'])->toBeNull();
});

it('sorts items by section, order and title', function () {
    $items = AdminNavigation::normalizeItems([
        ['title' => 'Zulu', 'href' => '/zulu', 'group' => 'tools'],
        ['title' => 'Alpha', 'href' => '/alpha', 'group' => 'tools'],
        ['title' => 'First', 'href' => '/first', 'group' => 'tools', 'order' => 10],
        ['title' => 'Commerce', 'href' => '/commerce', 'group' => 'commerce'],
        ['title' => 'Dashboard extra', 'href' => '/extra', 'group' => 'main'],
    ]);

    expect(array_column($items, 'title'))->toBe([
        'Dashboard extra',
        'Commerce',
        'First',
        'Alpha',
        'Zulu',
    ]);
});

it('drops items without a usable title or href', function () {
    $items = AdminNavigation::normalizeItems([
        ['title' => 'Missing href'],
        ['href' => '/missing-title'],
        ['title' => '   ', 'href' => '/blank'],
        ['title' => 'Valid', 'href' => '/valid'],
        'not-an-array',
    ]);

    expect($items)->toHaveCount(1)
        ->and($items[0]['title'])->toBe('Valid');
});

it('hides permission-protected items from users without the permission', function () {
    $user = new class
    {
        public function can(string $ability): bool
        {
            return $ability === 'manage plugins';
        }
    };

    $items = [
        ['title' => 'Plugins', 'href' => '/admin/plugins', 'group' => 'system', 'permission' => 'manage plugins'],
        ['title' => 'Secrets', 'href' => '/admin/secrets', 'group' => 'system', 'permission' => 'manage secrets'],
        ['title' => 'Public', 'href' => '/admin/public', 'group' => 'extensions'],
    ];

    expect(array_column(AdminNavigation::normalizeItems($items, $user), 'title'))
        ->toBe(['Plugins', 'Public'])
        ->and(array_column(AdminNavigation::normalizeItems($items), 'title'))
        ->toBe(['Public']);
});

it('keeps the PHP icon allowlist in sync with the sidebar icon map', function () {
    $source = file_get_contents(resource_path('js/components/app-sidebar.tsx'));

    expect($source)->toBeString();

    preg_match('/const iconMap = \{(.*?)\} as const;/s', (string) $source, $block);
    expect($block[1] ?? '')->not->toBe('');

    preg_match_all('/^\s*([A-Za-z0-9_]+),$/m', $block[1], $matches);

    $icons = $matches[1];
    sort($icons);

    $expected = AdminNavigation::ICONS;
    sort($expected);

    expect($icons)->toBe($expected);
});
