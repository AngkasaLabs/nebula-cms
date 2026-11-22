<?php

use App\Exceptions\StoreException;
use App\Models\Plugin;
use App\Models\Setting;
use App\Services\StoreClient;
use App\Services\StoreUpdates;
use Illuminate\Support\Facades\Http;

it('reads the store URL from settings before falling back to config', function () {
    config(['nebula.store_url' => 'https://default-store.test']);

    expect(app(StoreClient::class)->baseUrl())->toBe('https://default-store.test');

    Setting::set('store_url', 'https://custom-store.test/');

    expect(app(StoreClient::class)->baseUrl())->toBe('https://custom-store.test');
});

it('is disabled when the setting is turned off', function () {
    Setting::set('store_enabled', false);

    expect(app(StoreClient::class)->enabled())->toBeFalse();
});

it('fetches the catalog from the store API', function () {
    Setting::set('store_url', 'https://store.test');

    Http::fake([
        'https://store.test/api/store/v1/packages*' => Http::response([
            'data' => [
                ['slug' => 'demo', 'type' => 'plugin', 'name' => 'Demo', 'latest_version' => '1.0.0'],
            ],
            'meta' => ['current_page' => 1, 'last_page' => 1, 'total' => 1],
        ]),
    ]);

    $catalog = app(StoreClient::class)->catalog(['type' => 'plugin']);

    expect($catalog['data'])->toHaveCount(1);
    expect($catalog['data'][0]['slug'])->toBe('demo');
    expect($catalog['meta']['total'])->toBe(1);
});

it('surfaces store errors as exceptions', function () {
    Setting::set('store_url', 'https://store.test');

    Http::fake([
        'https://store.test/api/store/v1/packages*' => Http::response(['message' => 'Server exploded'], 500),
    ]);

    expect(fn () => app(StoreClient::class)->catalog())
        ->toThrow(StoreException::class, 'Server exploded');
});

it('requests updates for installed packages', function () {
    Setting::set('store_url', 'https://store.test');

    Http::fake([
        'https://store.test/api/store/v1/updates' => Http::response([
            'data' => [[
                'slug' => 'demo',
                'type' => 'plugin',
                'current' => '1.0.0',
                'latest' => '1.1.0',
            ]],
        ]),
    ]);

    $updates = app(StoreClient::class)->updates([
        ['slug' => 'demo', 'type' => 'plugin', 'version' => '1.0.0'],
    ]);

    expect($updates)->toHaveCount(1);
    expect($updates[0]['latest'])->toBe('1.1.0');
});

it('caches and summarises store updates', function () {
    Plugin::create([
        'name' => 'Demo',
        'slug' => 'demo',
        'folder_name' => 'demo',
        'version' => '1.0.0',
    ]);

    Setting::set('store_url', 'https://store.test');

    Http::fake([
        'https://store.test/api/store/v1/updates' => Http::response([
            'data' => [[
                'slug' => 'demo',
                'type' => 'plugin',
                'current' => '1.0.0',
                'latest' => '1.1.0',
            ]],
        ]),
    ]);

    $service = app(StoreUpdates::class);
    $service->refresh();

    $summary = $service->summary();

    expect($summary['count'])->toBe(1);
    expect($summary['items'][0])->toMatchArray(['slug' => 'demo', 'type' => 'plugin', 'latest' => '1.1.0']);
    expect($service->map())->toHaveKey('plugin:demo');
});
