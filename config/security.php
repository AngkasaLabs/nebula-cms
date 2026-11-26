<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Security Headers
    |--------------------------------------------------------------------------
    |
    | Baseline hardening headers sent on every web response. HSTS is only sent
    | over HTTPS. Disable only when a reverse proxy already sets these headers.
    |
    */

    'headers_enabled' => filter_var(env('SECURITY_HEADERS_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Content Security Policy
    |--------------------------------------------------------------------------
    |
    | Ships as report-only in 1.13 so plugin inline scripts surface as reports
    | before they break. Set SECURITY_CSP_REPORT_ONLY=false to enforce.
    |
    */

    'csp_enabled' => filter_var(env('SECURITY_CSP_ENABLED', true), FILTER_VALIDATE_BOOLEAN),

    'csp_report_only' => filter_var(env('SECURITY_CSP_REPORT_ONLY', true), FILTER_VALIDATE_BOOLEAN),

    'csp_report_uri' => '/csp-report',

];
