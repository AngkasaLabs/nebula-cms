<?php

use App\Support\PackageSigningKeys;
use Tests\TestCase;

uses(TestCase::class);

function signing_key_defaults(): array
{
    return ['official' => 'b2ZmaWNpYWwta2V5'];
}

it('keeps the built-in keys when no env keys are configured', function () {
    expect(PackageSigningKeys::resolve(signing_key_defaults(), null))->toBe(signing_key_defaults())
        ->and(PackageSigningKeys::resolve(signing_key_defaults(), ''))->toBe(signing_key_defaults())
        ->and(PackageSigningKeys::resolve(signing_key_defaults(), '   '))->toBe(signing_key_defaults());
});

it('adds env keys on top of the built-in keys', function () {
    $resolved = PackageSigningKeys::resolve(signing_key_defaults(), '{"local":"bG9jYWwta2V5"}');

    expect($resolved)->toBe([
        'official' => 'b2ZmaWNpYWwta2V5',
        'local' => 'bG9jYWwta2V5',
    ]);
});

it('lets an env key replace a built-in key with the same id', function () {
    $resolved = PackageSigningKeys::resolve(signing_key_defaults(), '{"official":"cm90YXRlZC1rZXk="}');

    expect($resolved)->toBe(['official' => 'cm90YXRlZC1rZXk=']);
});

it('trusts only env keys when replace mode is enabled', function () {
    $resolved = PackageSigningKeys::resolve(signing_key_defaults(), '{"local":"bG9jYWwta2V5"}', true);

    expect($resolved)->toBe(['local' => 'bG9jYWwta2V5']);
});

it('falls back to the built-in keys when the env JSON is invalid', function () {
    expect(PackageSigningKeys::resolve(signing_key_defaults(), '{not-json'))->toBe(signing_key_defaults())
        ->and(PackageSigningKeys::resolve(signing_key_defaults(), '"string"'))->toBe(signing_key_defaults());
});

it('drops entries that are not key id / public key strings', function () {
    $resolved = PackageSigningKeys::resolve(
        signing_key_defaults(),
        '{"ok":"a2V5","empty":"","bad":123,"":"bmtleQ==","0":"bnVtZXJpYw=="}'
    );

    expect($resolved)->toBe([
        'official' => 'b2ZmaWNpYWwta2V5',
        'ok' => 'a2V5',
    ]);
});

it('ships the official key as the default trust anchor', function () {
    expect(config('nebula.package_signing_keys'))->toHaveKey('official')
        ->and(config('nebula.package_signing_keys')['official'])->toBe('34BVMN/vudFGIXCtDE7HCfmt7LpTeFQZCcLVtC+qb2w=');
});
