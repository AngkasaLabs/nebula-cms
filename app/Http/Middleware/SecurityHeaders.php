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
        $directives = [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            "style-src 'self' 'unsafe-inline' https://fonts.bunny.net",
            "img-src 'self' data: blob: https:",
            "font-src 'self' data: https://fonts.bunny.net",
            "connect-src 'self'",
            "media-src 'self' blob: https:",
            "frame-src 'self' https:",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ];

        $reportUri = (string) config('security.csp_report_uri', '/csp-report');
        if ($reportUri !== '') {
            $directives[] = 'report-uri '.$reportUri;
        }

        $header = config('security.csp_report_only', true)
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';

        $response->headers->set($header, implode('; ', $directives).';');
    }
}
