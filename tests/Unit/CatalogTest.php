<?php

declare(strict_types=1);

use Snippet\Content\Catalog;
use Snippet\Content\Page;
use Snippet\Exception\ContentException;
use Snippet\Markdown\Document;
use Snippet\Markdown\InlineArena;

it('rejects duplicate canonical routes', function (): void {
    $document = new Document('', [], [], new InlineArena('', 0));
    $first = new Page('same', 'First', 'D', $document, []);
    $second = new Page('same', 'Second', 'D', $document, []);
    new Catalog([], [$first, $second]);
})->throws(ContentException::class, "Route collision at /same/ between 'same' and 'same'.");
