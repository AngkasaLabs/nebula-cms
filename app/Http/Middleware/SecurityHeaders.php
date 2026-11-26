<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds baseline security headers and a report-only Content Security Policy.
 *
 * A per-request nonce is shared with Blade/Vite so the Inertia root template and
 * Ziggy routes survive a future enforced policy without inline-script exceptions.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = base64_encode(random_bytes(16));

        $request->attributes->set('csp_nonce', $nonce);
        View::share('cspNonce', $nonce);
        Vite::useCspNonce($nonce);

        $response = $next($request);

        if (config('security.headers_enabled', true)) {
            $this->applyBaselineHeaders($request, $response);
        }

        if (config('security.csp_enabled', true)) {
            $this->applyCsp($nonce, $response);
        }

        return $response;
    }

    protected function applyBaselineHeaders(Request $request, Response $response): void
    {
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
            'X-Permitted-Cross-Domain-Policies' => 'none',
        ];

        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        foreach ($headers as $name => $value) {
            $response->headers->set($name, $value);
        }
    }

    protected function applyCsp(string $nonce, Response $response): void
    {
        $scriptSrc = array_values(array_unique([
            "'self'",
            "'nonce-{$nonce}'",
            ...config('security.csp_script_src', []),
        ]));

        $styleSrc = array_values(array_unique([
            "'self'",
            "'unsafe-inline'",
            ...config('security.csp_style_src', []),
        ]));

        $directives = [
            'default-src' => ["'self'"],
            'script-src' => $scriptSrc,
            'style-src' => $styleSrc,
            'img-src' => ['self', 'data:', 'blob:', 'https:'],
            'font-src' => ['self', 'data:', 'https://fonts.bunny.net'],
            'connect-src' => ["'self'"],
            'media-src' => ["'self'", 'blob:', 'https:'],
            'frame-src' => ["'self'", 'https:'],
            'object-src' => ["'none'"],
            'base-uri' => ["'self'"],
            'form-action' => ["'self'"],
            'frame-ancestors' => ["'none'"],
        ];

        // Quotes are required for keyword sources only; normalise the common
        // mistake of writing `self` instead of `'self'` in config or filters.
        $directives = array_map(function (array $sources): array {
            return array_map(fn (string $source) => in_array($source, ['self', 'none', 'unsafe-inline', 'unsafe-eval'], true)
                ? "'{$source}'"
                : $source, $sources);
        }, $directives);

        $directives = apply_filters('security.csp_directives', $directives, $nonce);

        if (! is_array($directives)) {
            $directives = [];
        }

        $reportUri = (string) config('security.csp_report_uri', '/csp-report');
        if ($reportUri !== '') {
            $directives['report-uri'] = [$reportUri];
        }

        $parts = [];
        foreach ($directives as $name => $sources) {
            $parts[] = is_array($sources)
                ? trim($name.' '.implode(' ', $sources))
                : trim($name.' '.$sources);
        }

        $header = config('security.csp_report_only', true)
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';

        $response->headers->set($header, implode('; ', $parts).';');
    }
}
