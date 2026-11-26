<?php

namespace App\Support;

use Symfony\Component\HtmlSanitizer\HtmlSanitizer as SymfonyHtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Sanitises editor HTML to the markup the admin editor is allowed to produce.
 *
 * Scripts, event handlers, javascript: URLs and CSS injection are removed while
 * headings, lists, tables, images and safe links survive.
 */
class HtmlSanitizer
{
    protected ?SymfonyHtmlSanitizer $sanitizer = null;

    public function sanitize(?string $html): ?string
    {
        if ($html === null || trim($html) === '') {
            return $html;
        }

        return $this->instance()->sanitize($html);
    }

    public function isClean(?string $html): bool
    {
        return $this->sanitize($html) === $html;
    }

    protected function instance(): SymfonyHtmlSanitizer
    {
        return $this->sanitizer ??= new SymfonyHtmlSanitizer(
            (new HtmlSanitizerConfig)
                ->allowSafeElements()
                ->allowLinkSchemes(['http', 'https', 'mailto', 'tel'])
                ->allowRelativeLinks()
                ->allowMediaSchemes(['http', 'https'])
                ->allowRelativeMedias()
                ->withMaxInputLength(2_000_000)
        );
    }
}
