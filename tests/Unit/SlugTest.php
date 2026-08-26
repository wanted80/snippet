<?php

declare(strict_types=1);

use Snippet\Support\Slug;

it('generates deterministic canonical Unicode slugs', function (string $value, string $slug): void {
    expect(Slug::from($value))->toBe($slug);
})->with([
    ['PHP & Web', 'php-web'],
    ['PHP 8.5', 'php-8-5'],
    ['  Many---Separators  ', 'many-separators'],
    ['Café', 'café'],
    ['日本語', '日本語'],
    ['Crème brûlée', 'crème-brûlée'],
    ['A/B? C#D%', 'a-b-c-d'],
    ["bad\xFF", ''],
]);

it('encodes a canonical slug as one RFC 3986 path segment', function (string $slug, string $segment): void {
    expect(Slug::toUriSegment($slug))->toBe($segment);
})->with([
    ['php-web', 'php-web'],
    ['café', 'caf%C3%A9'],
    ['日本語', '%E6%97%A5%E6%9C%AC%E8%AA%9E'],
]);

it('accepts only canonical lowercase ASCII content slugs', function (string $slug, bool $canonical): void {
    expect(Slug::isCanonicalAscii($slug))->toBe($canonical);
})->with([
    ['post', true],
    ['php-8-5', true],
    ['123', true],
    ['', false],
    ['Bad', false],
    ['bad_slug', false],
    ['double--hyphen', false],
    ['café', false],
    ['日本語', false],
]);
