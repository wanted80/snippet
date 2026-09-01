<?php

declare(strict_types=1);

use Snippet\Content\Article;
use Snippet\Content\Catalog;
use Snippet\Content\Page;
use Snippet\Content\Tag;
use Snippet\Content\TagUsage;
use Snippet\Exception\ContentException;
use Snippet\Markdown\Document;
use Snippet\Markdown\InlineArena;

mutates(Catalog::class);

it('rejects duplicate canonical routes', function (): void {
    $document = new Document('', [], [], new InlineArena('', 0));
    $first = new Page('same', 'First', 'D', $document, []);
    $second = new Page('same', 'Second', 'D', $document, []);
    new Catalog([], [$first, $second]);
})->throws(ContentException::class, "Route collision at /same/ between 'same' and 'same'.");

it('orders equal-count tags by labels before their slug tie-breaker', function (): void {
    $document = new Document('', [], [], new InlineArena('', 0));
    $catalog = new Catalog([
        new Article('zulu', 'Zulu', 'D', '2026-01-02', [new Tag('Zulu', 'alpha')], $document, []),
        new Article('alpha', 'Alpha', 'D', '2026-01-01', [new Tag('Alpha', 'zulu')], $document, []),
    ], []);

    expect(array_map(static fn(TagUsage $usage): string => $usage->tag->label, $catalog->tagUsages()))
        ->toBe(['Alpha', 'Zulu']);
});

it('keeps the first deterministic label when articles share a tag slug', function (): void {
    $document = new Document('', [], [], new InlineArena('', 0));
    $catalog = new Catalog([
        new Article('first', 'First', 'D', '2026-01-02', [new Tag('Primary', 'shared')], $document, []),
        new Article('second', 'Second', 'D', '2026-01-01', [new Tag('Alternate', 'shared')], $document, []),
    ], []);

    expect(array_map(static fn(Tag $tag): string => $tag->label . ':' . $tag->slug, $catalog->tags()))
        ->toBe(['Primary:shared'])
        ->and(array_map(
            static fn(TagUsage $usage): string => $usage->tag->label . ':' . $usage->tag->slug . ':' . $usage->articles,
            $catalog->tagUsages(),
        ))
        ->toBe(['Primary:shared:2']);
});
