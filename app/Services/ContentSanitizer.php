<?php

namespace App\Services;

use App\Support\HtmlSanitizer;

/**
 * Applies the content sanitisation policy for the acting user.
 *
 * Users without the `unfiltered_html` permission (everyone except Admin and
 * Super Admin by default) get their editor HTML sanitised before it is stored.
 */
class ContentSanitizer
{
    public function __construct(
        protected HtmlSanitizer $sanitizer,
    ) {}

    public function sanitizeForUser(?string $html, ?object $user = null): ?string
    {
        if ($html === null || $html === '') {
            return $html;
        }

        if ($user !== null && method_exists($user, 'can') && $user->can('unfiltered_html')) {
            return $html;
        }

        return $this->sanitizer->sanitize($html);
    }
}
