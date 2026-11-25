<?php

use App\Support\ContentSearch;

it('escapes LIKE wildcards in ContentSearch', function () {
    expect(ContentSearch::escapeLike('100%_off'))->toBe('100\%\_off');
});

it('normalises search terms', function () {
    expect(ContentSearch::normalizeTerm('  cake  '))->toBe('cake')
        ->and(ContentSearch::normalizeTerm('   '))->toBeNull()
        ->and(ContentSearch::normalizeTerm(null))->toBeNull();
});
