<?php

use App\Support\HtmlSanitizer;
use Tests\TestCase;

uses(TestCase::class);

it('removes scripts, event handlers and javascript urls', function () {
    $clean = (new HtmlSanitizer)->sanitize(
        '<h2>Hello</h2><p>ok<script>alert(1)</script>'
        .'<img src="https://example.test/a.jpg" onerror="alert(1)">'
        .'<a href="javascript:alert(1)">click</a></p>'
    );

    expect($clean)->toContain('<h2>Hello</h2>')
        ->toContain('<p>ok')
        ->toContain('https://example.test/a.jpg')
        ->not->toContain('<script')
        ->not->toContain('onerror')
        ->not->toContain('javascript:');
});

it('keeps editor markup such as tables, lists and links', function () {
    $clean = (new HtmlSanitizer)->sanitize(
        '<ul><li>one</li></ul>'
        .'<table><tbody><tr><td>cell</td></tr></tbody></table>'
        .'<a href="https://example.test/page" target="_blank">link</a>'
    );

    expect($clean)->toContain('<ul>')
        ->toContain('<table>')
        ->toContain('<td>cell</td>')
        ->toContain('href="https://example.test/page"');
});

it('removes inline styles that could inject CSS urls', function () {
    $clean = (new HtmlSanitizer)->sanitize('<p style="background:url(javascript:alert(1))">x</p>');

    expect($clean)->toBe('<p>x</p>');
});

it('drops iframes and embedded objects', function () {
    $clean = (new HtmlSanitizer)->sanitize('<iframe src="https://evil.test"></iframe><object data="x"></object><p>safe</p>');

    expect($clean)->not->toContain('<iframe')
        ->not->toContain('<object')
        ->toContain('<p>safe</p>');
});

it('leaves empty content untouched', function () {
    $sanitizer = new HtmlSanitizer;

    expect($sanitizer->sanitize(null))->toBeNull()
        ->and($sanitizer->sanitize(''))->toBe('')
        ->and($sanitizer->isClean(''))->toBeTrue();
});
