<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Support\SecuritySettings;
use Illuminate\Http\Request;

class ContentSecurityController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:manage settings');
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'enforce' => ['required', 'boolean'],
        ]);

        SecuritySettings::setCspReportOnly(! $validated['enforce']);

        if (function_exists('activity') && auth()->check()) {
            activity()
                ->causedBy(auth()->user())
                ->withProperties(['enforce' => (bool) $validated['enforce']])
                ->log('csp_mode_updated');
        }

        return back()->with(
            'success',
            $validated['enforce']
                ? 'Content Security Policy sekarang aktif (enforce).'
                : 'Content Security Policy kembali ke mode report-only.',
        );
    }
}
