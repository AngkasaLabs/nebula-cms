<?php

namespace App\Http\Controllers\Admin\Settings;

use App\Http\Controllers\Controller;
use App\Services\ContentSafetyAuditor;
use Illuminate\Http\RedirectResponse;

/**
 * Admin-side review and cleanup for stored editor HTML.
 */
class ContentSafetyController extends Controller
{
    public function __construct()
    {
        $this->middleware('permission:manage settings');
    }

    public function scan(ContentSafetyAuditor $auditor): RedirectResponse
    {
        @set_time_limit(120);

        return redirect()
            ->route('admin.settings.security')
            ->with('contentSafetyReport', $auditor->scan());
    }

    public function apply(ContentSafetyAuditor $auditor): RedirectResponse
    {
        @set_time_limit(120);

        return redirect()
            ->route('admin.settings.security')
            ->with('contentSafetyReport', $auditor->apply())
            ->with('contentSafetyApplied', true);
    }
}
