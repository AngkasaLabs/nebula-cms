<?php

namespace App\Services;

use App\Exceptions\StoreException;
use App\Models\Setting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * HTTP client for the NebulaCMS package registry (`/api/store/v1`).
 */
class StoreClient
{
    public function enabled(): bool
    {
        $enabled = Setting::get('store_enabled', config('nebula.store_enabled', true));

        if (! filter_var($enabled, FILTER_VALIDATE_BOOLEAN)) {
            return false;
        }

        return $this->baseUrl() !== '';
    }

    public function baseUrl(): string
    {
        $url = Setting::get('store_url');

        if (! is_string($url) || trim($url) === '') {
            $url = (string) config('nebula.store_url', '');
        }

        return rtrim(trim($url), '/');
    }

    /**
     * @param  array{type?: string|null, q?: string|null, category?: string|null, page?: int|null, per_page?: int|null}  $query
     * @return array{data: array<int, array<string, mixed>>, meta: array<string, mixed>}
     */
    public function catalog(array $query = []): array
    {
        $this->ensureEnabled();

        $cacheKey = $this->cachePrefix().'catalog:'.md5((string) json_encode($query));

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($query) {
            $payload = $this->get('/api/store/v1/packages', array_filter($query, fn ($value) => $value !== null && $value !== ''));

            return [
                'data' => is_array($payload['data'] ?? null) ? $payload['data'] : [],
                'meta' => is_array($payload['meta'] ?? null) ? $payload['meta'] : [],
            ];
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function package(string $slug): array
    {
        $this->ensureEnabled();

        return Cache::remember($this->cachePrefix()."package:{$slug}", now()->addMinutes(5), function () use ($slug) {
            $payload = $this->get('/api/store/v1/packages/'.rawurlencode($slug));

            if (! is_array($payload['data'] ?? null)) {
                throw new StoreException('The store returned an invalid package payload.');
            }

            return $payload['data'];
        });
    }

    /**
     * @param  array<int, array{slug: string, type: string, version: string}>  $installed
     * @return array<int, array<string, mixed>>
     */
    public function updates(array $installed): array
    {
        $this->ensureEnabled();

        $payload = $this->post('/api/store/v1/updates', [
            'cms_version' => (string) config('nebula.version', '0.0.0'),
            'installed' => array_values($installed),
        ]);

        return is_array($payload['data'] ?? null) ? $payload['data'] : [];
    }

    /**
     * @return array{slug: string, type: string, version: string, url: string, manifest: array<string, mixed>, signature: string, changelog?: string|null}
     */
    public function download(string $slug, ?string $version = null): array
    {
        $this->ensureEnabled();

        $query = $version !== null && $version !== '' ? ['version' => $version] : [];
        $payload = $this->get('/api/store/v1/packages/'.rawurlencode($slug).'/download', $query);

        foreach (['slug', 'type', 'version', 'url', 'manifest', 'signature'] as $field) {
            if (! isset($payload[$field])) {
                throw new StoreException("The store download payload is missing [{$field}].");
            }
        }

        if (! is_array($payload['manifest']) || ! is_string($payload['signature']) || ! is_string($payload['url'])) {
            throw new StoreException('The store download payload is invalid.');
        }

        return $payload;
    }

    public function forgetCatalogCache(): void
    {
        Cache::forever('store:cache_version', (int) Cache::get('store:cache_version', 1) + 1);
    }

    protected function cachePrefix(): string
    {
        return 'store:v'.(int) Cache::get('store:cache_version', 1).':';
    }

    protected function ensureEnabled(): void
    {
        if (! $this->enabled()) {
            throw new StoreException('The package store is disabled. Configure it in Settings → General.');
        }
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    protected function get(string $path, array $query = []): array
    {
        try {
            $response = Http::timeout((int) config('nebula.store_timeout', 15))
                ->acceptJson()
                ->withHeaders(['User-Agent' => 'NebulaCMS/'.config('nebula.version')])
                ->get($this->baseUrl().$path, $query);
        } catch (ConnectionException $e) {
            throw new StoreException('Could not reach the package store.', previous: $e);
        }

        return $this->decode($response->status(), $response->json());
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    protected function post(string $path, array $body): array
    {
        try {
            $response = Http::timeout((int) config('nebula.store_timeout', 15))
                ->acceptJson()
                ->withHeaders(['User-Agent' => 'NebulaCMS/'.config('nebula.version')])
                ->post($this->baseUrl().$path, $body);
        } catch (ConnectionException $e) {
            throw new StoreException('Could not reach the package store.', previous: $e);
        }

        return $this->decode($response->status(), $response->json());
    }

    /**
     * @return array<string, mixed>
     */
    protected function decode(int $status, mixed $payload): array
    {
        if ($status < 200 || $status >= 300) {
            $message = is_array($payload) && is_string($payload['message'] ?? null)
                ? $payload['message']
                : "The package store responded with HTTP {$status}.";

            throw new StoreException($message);
        }

        if (! is_array($payload)) {
            throw new StoreException('The package store returned an invalid response.');
        }

        return $payload;
    }
}
