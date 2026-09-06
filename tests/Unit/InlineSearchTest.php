<?php

declare(strict_types=1);

use Snippet\Markdown\InlineSearch;
use Snippet\Tests\PublisherFaults;

mutates(InlineSearch::class);

it('searches an absent delimiter only once as the parser moves forward', function (): void {
    $source = str_repeat('[', 4096);
    $search = new InlineSearch($source);
    for ($offset = 0; $offset < 4096; ++$offset) {
        expect($search->find(']', $offset, 4096))->toBeNull();
    }

    expect(PublisherFaults::calls('markdown_search'))->toBe(1);
});

it('reuses a known match across smaller ranges without crossing their end', function (): void {
    $search = new InlineSearch('one]two]');

    expect($search->find(']', 0, 2))->toBeNull()
        ->and($search->find(']', 0, 2))->toBeNull()
        ->and($search->find(']', 2, 3))->toBeNull()
        ->and($search->find(']', 2, 4))->toBe(3)
        ->and($search->find(']', 3, 4))->toBe(3)
        ->and(PublisherFaults::calls('markdown_search'))->toBe(1)
        ->and($search->find(']', 4, 8))->toBe(7)
        ->and($search->find(']', 0, 8))->toBe(3)
        ->and(PublisherFaults::calls('markdown_search'))->toBe(3);
});

it('does not search empty ranges or let an earlier instance affect another source', function (): void {
    $first = new InlineSearch('plain]');
    $second = new InlineSearch(']');

    expect($first->find(']', 5, 5))->toBeNull()
        ->and($first->find(']', 5, 4))->toBeNull()
        ->and($first->find(')', 0, 6))->toBeNull()
        ->and($second->find(']', 0, 1))->toBe(0)
        ->and(PublisherFaults::calls('markdown_search'))->toBe(2);
});

it('remembers that whitespace-preceded style delimiters cannot close emphasis', function (): void {
    $source = str_repeat('*a ', 4096);
    $search = new InlineSearch($source);
    for ($offset = 0; $offset < 4096; ++$offset) {
        expect($search->styleEnd('*', $offset * 3, 12_288))->toBeNull();
    }

    expect(PublisherFaults::calls('markdown_search'))->toBe(4097);
});

it('distinguishes literal delimiters from valid style closers around Unicode whitespace', function (): void {
    $search = new InlineSearch("*日　*é* **x**");

    expect($search->find('*', 0, 16))->toBe(0)
        ->and($search->styleEnd('*', 0, 16))->toBe(10)
        ->and($search->styleEnd('**', 0, 16))->toBe(15)
        ->and($search->isNonWhitespaceAt(1))->toBeTrue()
        ->and($search->isNonWhitespaceAt(4))->toBeFalse()
        ->and($search->isNonWhitespaceAt(11))->toBeFalse()
        ->and($search->isNonWhitespaceAt(14))->toBeTrue();
});

it('skips isolated markers when looking for a paired delimiter', function (): void {
    $search = new InlineSearch('a*b**c*');

    expect($search->find('**', 0, 7))->toBe(3)
        ->and($search->find('**', 5, 7))->toBeNull();
});
