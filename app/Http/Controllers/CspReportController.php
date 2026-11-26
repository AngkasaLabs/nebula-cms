<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Receives Content-Security-Policy reports while the policy is report-only.
 *
 * Browsers post these without cookies or a CSRF token, so the endpoint is
 * unauthenticated, size-capped and throttled at the route level.
 */
class CspReportController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $raw = $request->getContent();

        if (! is_string($raw) || $raw === '' || strlen($raw) > 16384) {
            return response()->noContent();
        }

        $payload = json_decode($raw, true);

        if (! is_array($payload)) {
            return response()->noContent();
        }

        $report = $payload['csp-report'] ?? $payload;

        if (! is_array($report)) {
            return response()->noContent();
        }

        Log::warning('CSP violation reported', [
            'document_uri' => substr((string) ($report['document-uri'] ?? ''), 0, 512),
            'violated_directive' => substr((string) ($report['violated-directive'] ?? $report['effective-directive'] ?? ''), 0, 128),
            'blocked_uri' => substr((string) ($report['blocked-uri'] ?? ''), 0, 512),
            'source_file' => substr((string) ($report['source-file'] ?? ''), 0, 512),
            'line_number' => $report['line-number'] ?? null,
        ]);

        return response()->noContent();
    }
}
