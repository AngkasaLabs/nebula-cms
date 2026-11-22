<?php

use App\Exceptions\StoreException;
use App\Models\Setting;
use App\Services\StoreClient;
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
