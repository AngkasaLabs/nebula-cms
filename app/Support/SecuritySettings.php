<?php

namespace App\Support;

use App\Models\Setting;
use Throwable;

/**
 * Admin-managed security switches. Values fall back to `config/security.php`.
 */
class SecuritySettings
{
    public const CSP_REPORT_ONLY = 'csp_report_only';

    public static function cspReportOnly(): bool
    {
        try {
            $setting = Setting::query()->where('key', self::CSP_REPORT_ONLY)->first();
        } catch (Throwable) {
            return (bool) config('security.csp_report_only', true);
        }

        if ($setting === null) {
            return (bool) config('security.csp_report_only', true);
        }

        return filter_var($setting->value, FILTER_VALIDATE_BOOLEAN);
    }

    public static function setCspReportOnly(bool $reportOnly): void
    {
        Setting::query()->updateOrCreate(
            ['key' => self::CSP_REPORT_ONLY],
            [
                'group' => 'security',
                'value' => $reportOnly,
                'type' => 'boolean',
                'is_public' => false,
            ],
        );

        config(['security.csp_report_only' => $reportOnly]);
    }

    public static function applyToConfig(): void
    {
        config(['security.csp_report_only' => self::cspReportOnly()]);
    }
}
