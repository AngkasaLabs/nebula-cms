<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

it('sends baseline security headers', function () {
    $this->get(route('login', absolute: false))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
});

it('only sends HSTS over https', function () {
    $this->get(route('login', absolute: false))
        ->assertHeaderMissing('Strict-Transport-Security');

    $this->get('https://localhost/login')
        ->assertHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
});

it('sends a report-only CSP with a nonce and report endpoint', function () {
    $response = $this->get(route('login', absolute: false))->assertOk();

    $header = $response->headers->get('Content-Security-Policy-Report-Only');

    expect($header)->toBeString()
        ->and($header)->toContain("default-src 'self'")
        ->and($header)->toContain("script-src 'self' 'nonce-")
        ->and($header)->toContain('report-uri /csp-report');
});

it('can disable the CSP and headers independently', function () {
    Config::set('security.csp_enabled', false);
    Config::set('security.headers_enabled', false);

    $this->get(route('login', absolute: false))
        ->assertOk()
        ->assertHeaderMissing('Content-Security-Policy-Report-Only')
        ->assertHeaderMissing('X-Frame-Options');
});

it('accepts browser CSP reports without authentication', function () {
    Cache::flush();

    $payload = json_encode([
        'csp-report' => [
            'document-uri' => 'https://example.test/admin',
            'violated-directive' => 'script-src',
            'blocked-uri' => 'https://evil.test/x.js',
            'line-number' => 12,
        ],
    ]);

    $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], $payload)
        ->assertNoContent();
});

it('ignores malformed CSP reports', function () {
    $this->call('POST', '/csp-report', [], [], [], ['CONTENT_TYPE' => 'application/csp-report'], '{not-json')
        ->assertNoContent();
});
