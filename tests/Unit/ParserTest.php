<?php

declare(strict_types=1);

use Snippet\Exception\ContentException;
use Snippet\Markdown\CodeBlock;
use Snippet\Markdown\Document;
use Snippet\Markdown\FlatList;
use Snippet\Markdown\Heading;
use Snippet\Markdown\Inline;
use Snippet\Markdown\InlineArena;
use Snippet\Markdown\InlineCode;
use Snippet\Markdown\InlineMarker;
use Snippet\Markdown\Link;
use Snippet\Markdown\Paragraph;
use Snippet\Markdown\Parser;
use Snippet\Markdown\Text;
use Snippet\Markdown\ThematicBreak;

mutates(Document::class, InlineArena::class, Parser::class);

it('parses every supported block and inline construct while preserving literal values', function (): void {
    $markdown = <<<'MARKDOWN'
# Heading `code`

Text <b>literal</b>, *emphasis*, **strong**, [web](https://example.com/a?b=1), [root](/docs/), and [relative](../file).
continued & literal

- first
* second

1. one
22. two

```php
echo "<&";
```
MARKDOWN;

    $document = new Parser()->parse($markdown, 'article.md');
    [$heading, $paragraph, $unordered, $ordered, $code] = $document->blocks;
    assert($heading instanceof Heading);
    assert($paragraph instanceof Paragraph);
    assert($unordered instanceof FlatList);
    assert($ordered instanceof FlatList);
    assert($code instanceof CodeBlock);

    $headingInlines = iterator_to_array($document->inlines($heading), false);
    $paragraphInlines = iterator_to_array($document->inlines($paragraph), false);
    $unorderedItems = iterator_to_array($document->items($unordered), false);
    $orderedItems = iterator_to_array($document->items($ordered), false);
    $link = array_find($paragraphInlines, static fn(Inline $inline): bool => $inline instanceof Link);
    assert($link instanceof Link);
    assert($headingInlines[1] instanceof InlineCode);
    $secondItemInline = iterator_to_array($document->inlines($unorderedItems[1]), false)[0];
    assert($secondItemInline instanceof Text);

    expect($document->blocks)->toHaveCount(5)
        ->and($heading->level)->toBe(1)
        ->and($document->text($headingInlines[1]))->toBe('code')
        ->and($paragraphInlines)->toContain(InlineMarker::EmphasisStart, InlineMarker::EmphasisEnd)
        ->and($paragraphInlines)->toContain(InlineMarker::StrongStart, InlineMarker::StrongEnd)
        ->and($document->text($link))->toBe('https://example.com/a?b=1')
        ->and($unordered->ordered)->toBeFalse()
        ->and($document->text($secondItemInline))->toBe('second')
        ->and($ordered->ordered)->toBeTrue()
        ->and($orderedItems)->toHaveCount(2)
        ->and($document->text($code))->toBe('echo "<&";')
        ->and($code->language)->toBe('php');
});

it('returns fresh zero-indexed generators for every document traversal', function (): void {
    $document = new Parser()->parse(
        "Intro [one](/one/) and *two*.\n\n- first\n- [second](/two/)",
        'generators.md',
    );
    [$paragraph, $list] = $document->blocks;
    assert($paragraph instanceof Paragraph);
    assert($list instanceof FlatList);

    $firstInlines = $document->inlines($paragraph);
    $secondInlines = $document->inlines($paragraph);
    $firstItems = $document->items($list);
    $secondItems = $document->items($list);
    $firstLinks = $document->links();
    $secondLinks = $document->links();

    expect((string) new ReflectionMethod(Document::class, 'inlines')->getReturnType())->toBe(Generator::class)
        ->and((string) new ReflectionMethod(Document::class, 'items')->getReturnType())->toBe(Generator::class)
        ->and((string) new ReflectionMethod(Document::class, 'links')->getReturnType())->toBe(Generator::class)
        ->and((string) new ReflectionMethod(InlineArena::class, 'range')->getReturnType())->toBe(Generator::class)
        ->and($firstInlines)->not->toBe($secondInlines)
        ->and($firstItems)->not->toBe($secondItems)
        ->and($firstLinks)->not->toBe($secondLinks);

    $inlines = iterator_to_array($firstInlines);
    $repeatedInlines = iterator_to_array($secondInlines);
    $items = iterator_to_array($firstItems);
    $repeatedItems = iterator_to_array($secondItems);
    $links = iterator_to_array($firstLinks);
    $repeatedLinks = iterator_to_array($secondLinks);
    $firstItemInlines = iterator_to_array($document->inlines($items[0]));
    $secondItemInlines = iterator_to_array($document->inlines($items[1]));

    $inlineTypes = array_map(
        static fn(Inline $inline): string => $inline instanceof InlineMarker ? $inline->value : $inline::class,
        $inlines,
    );
    $linkTargets = array_map($document->text(...), $links);

    expect(array_keys($inlines))->toBe([0, 1, 2, 3, 4, 5, 6, 7, 8])
        ->and($inlineTypes)->toBe([
            Text::class,
            Link::class,
            Text::class,
            InlineMarker::LinkEnd->value,
            Text::class,
            InlineMarker::EmphasisStart->value,
            Text::class,
            InlineMarker::EmphasisEnd->value,
            Text::class,
        ])
        ->and($repeatedInlines)->toEqual($inlines)
        ->and(array_keys($items))->toBe([0, 1])
        ->and($repeatedItems)->toEqual($items)
        ->and($firstItemInlines)->toEqual([new Text(33, 5)])
        ->and($secondItemInlines)->toEqual([new Link(50, 5), new Text(42, 6), InlineMarker::LinkEnd])
        ->and(array_keys($links))->toBe([0, 1])
        ->and($linkTargets)->toBe(['/one/', '/two/'])
        ->and($repeatedLinks)->toEqual($links);
});

it('supports valid heading levels and an unlabelled empty code fence', function (): void {
    $document = new Parser()->parse("Paragraph\n# One\n\n## Two\n\n### Three\n\n```\n```", 'x.md');
    [$paragraph, $headingOne, $headingTwo, $headingThree, $code] = $document->blocks;
    assert($paragraph instanceof Paragraph);
    assert($headingOne instanceof Heading);
    assert($headingTwo instanceof Heading);
    assert($headingThree instanceof Heading);
    assert($code instanceof CodeBlock);
    expect($headingOne->level)->toBe(1)
        ->and($headingTwo->level)->toBe(2)
        ->and($headingThree->level)->toBe(3)
        ->and($document->text($code))->toBeEmpty()
        ->and($code->language)->toBeNull();
});

it('normalizes line endings while streaming adjacent blocks and terminal lists', function (): void {
    $document = new Parser()->parse("plain\r\n```Php8_- \t\r\ncode\r\n``` \t\r\n- item", 'lines.md');
    [$paragraph, $code, $list] = $document->blocks;
    assert($paragraph instanceof Paragraph);
    assert($code instanceof CodeBlock);
    assert($list instanceof FlatList);

    expect($document->source)->toBe("plain\n```Php8_- \t\ncode\n``` \t\n- item")
        ->and($document->text($code))->toBe('code')
        ->and($code->language)->toBe('Php8_-')
        ->and(iterator_to_array($document->items($list), false))->toHaveCount(1);
});

it('lets thematic breaks interrupt a paragraph without a blank separator', function (string $break): void {
    $document = new Parser()->parse("Before.\n{$break}\nAfter.", 'break.md');
    [$before, $thematicBreak, $after] = $document->blocks;

    expect($before)->toBeInstanceOf(Paragraph::class)
        ->and($thematicBreak)->toBeInstanceOf(ThematicBreak::class)
        ->and($after)->toBeInstanceOf(Paragraph::class);
})->with([
    'hyphens' => '---',
    'asterisks' => '***',
]);

it('keeps malformed block and inline candidates literal while parsing Unicode styles', function (): void {
    $source = "123x\n-bad\n[broken]\n`a\nb`\n*éx* and *xé* and *ab * and **ab*cd**";
    $document = new Parser()->parse($source, 'literal.md');
    $paragraph = $document->blocks[0];
    assert($paragraph instanceof Paragraph);
    $inlines = iterator_to_array($document->inlines($paragraph), false);

    expect($document->blocks)->toHaveCount(1)
        ->and($inlines)->toContain(InlineMarker::EmphasisStart, InlineMarker::EmphasisEnd);
});

it('scans past non-closing style delimiters and preserves unclosed styles', function (): void {
    $document = new Parser()->parse("**ab*cd**\n\n*ab *", 'styles.md');
    [$strong, $unclosed] = $document->blocks;
    assert($strong instanceof Paragraph);
    assert($unclosed instanceof Paragraph);

    expect(iterator_to_array($document->inlines($strong), false))->toContain(InlineMarker::StrongStart, InlineMarker::StrongEnd)
        ->and(iterator_to_array($document->inlines($unclosed), false))->not->toContain(InlineMarker::EmphasisStart);
});

it('represents an empty parse with empty arenas', function (): void {
    $document = new Parser()->parse('', 'empty.md');

    expect($document->source)->toBeEmpty()
        ->and($document->blocks)->toBeEmpty()
        ->and(new InlineArena('', 0)->count())->toBe(0);
});

it('preserves multibyte text around inline constructs', function (): void {
    $source = 'Café — *naïve* 👋 [élan](https://example.com/elan).';
    $document = new Parser()->parse($source, 'unicode.md');
    $paragraph = $document->blocks[0];
    assert($paragraph instanceof Paragraph);

    $representation = array_map(
        fn(Inline $inline): string => match (true) {
            $inline instanceof Text => 'text:' . $document->text($inline),
            $inline instanceof Link => 'link:' . $document->text($inline),
            $inline instanceof InlineMarker => $inline->value,
            default => $inline::class,
        },
        iterator_to_array($document->inlines($paragraph), false),
    );

    expect($representation)->toBe([
        'text:Café — ',
        'emphasis-start',
        'text:naïve',
        'emphasis-end',
        'text: 👋 ',
        'link:https://example.com/elan',
        'text:élan',
        'link-end',
        'text:.',
    ]);
});

it('uses byte offsets when parsing strikethrough after multibyte text', function (string $source, array $expected): void {
    $document = new Parser()->parse($source, 'unicode-strikethrough.md');
    $paragraph = $document->blocks[0];
    assert($paragraph instanceof Paragraph);

    $representation = array_map(
        fn(Inline $inline): string => match (true) {
            $inline instanceof Text => 'text:' . $document->text($inline),
            $inline instanceof InlineMarker => $inline->value,
            default => $inline::class,
        },
        iterator_to_array($document->inlines($paragraph), false),
    );

    expect($representation)->toBe($expected);
})->with([
    'accented Latin prefix' => [
        'é ~~old~~',
        ['text:é ', 'strikethrough-start', 'text:old', 'strikethrough-end'],
    ],
    'CJK prefix and suffix' => [
        '中文 ~~old~~ after',
        ['text:中文 ', 'strikethrough-start', 'text:old', 'strikethrough-end', 'text: after'],
    ],
]);

it('recognizes multibyte characters and whitespace at emphasis boundaries', function (string $source, array $expected): void {
    $document = new Parser()->parse($source, 'unicode-emphasis.md');
    $paragraph = $document->blocks[0];
    assert($paragraph instanceof Paragraph);

    $representation = array_map(
        fn(Inline $inline): string => match (true) {
            $inline instanceof Text => 'text:' . $document->text($inline),
            $inline instanceof InlineMarker => $inline->value,
            default => $inline::class,
        },
        iterator_to_array($document->inlines($paragraph), false),
    );

    expect($representation)->toBe($expected);
})->with([
    'emphasis ending in accented Latin' => [
        'é *aé*',
        ['text:é ', 'emphasis-start', 'text:aé', 'emphasis-end'],
    ],
    'strong emphasis ending in CJK' => [
        '中 **a界**',
        ['text:中 ', 'strong-start', 'text:a界', 'strong-end'],
    ],
    'Unicode whitespace before a closing delimiter' => [
        'plain *a　*',
        ['text:plain *a　*'],
    ],
]);

it('preserves a large plain paragraph as one text node', function (): void {
    $source = str_repeat('ordinary text ', 10_000);
    $document = new Parser()->parse($source, 'large.md');
    $paragraph = $document->blocks[0];
    assert($paragraph instanceof Paragraph);
    $inlines = iterator_to_array($document->inlines($paragraph), false);
    assert($inlines[0] instanceof Text);

    expect($inlines)->toHaveCount(1)
        ->and($document->text($inlines[0]))->toBe($source);
});

it('treats unsupported and malformed markdown as ordinary text', function (): void {
    $source = "#### Four\n![image](bad)\n| table |\n<div>x</div>\n[broken](\n`broken\n  * nested";
    $document = new Parser()->parse($source, 'x.md');
    $paragraph = $document->blocks[0];
    assert($paragraph instanceof Paragraph);
    $inlines = iterator_to_array($document->inlines($paragraph), false);
    assert($inlines[0] instanceof Text);

    expect($document->blocks)->toHaveCount(1)
        ->and($inlines)->toHaveCount(1)
        ->and($document->text($inlines[0]))->toBe($source);
});

it('rejects unsafe link schemes and protocol-relative targets', function (string $target): void {
    (void) new Parser()->parse(sprintf('[bad](%s)', $target), 'content/post/article.md');
})->throws(ContentException::class, 'Unsafe link target')->with([
    'javascript' => 'javascript:alert(1',
    'data' => 'data:text/plain,hello',
    'mailto' => 'mailto:a@example.com',
    'protocol relative' => '//example.com/path',
    'whitespace' => 'hello world',
    'malformed https' => 'https://:',
]);

it('reports link errors on the correct line across block and inline contexts', function (string $source, int $line): void {
    expect(fn(): Document => new Parser()->parse($source, 'lines.md'))
        ->toThrow(ContentException::class, "Unsafe link target 'javascript:alert(1' in 'lines.md' at line {$line}.");
})->with([
    'after a fenced code block' => ["```\ncode\n```\n\n[bad](javascript:alert(1))", 5],
    'after a thematic break' => ["Before.\n---\n[bad](javascript:alert(1))", 3],
    'inside a multiline paragraph' => ["First line.\nSecond [bad](javascript:alert(1))", 2],
    'inside multiline emphasis' => ["First *line.\n[bad](javascript:alert(1))*", 2],
    'after multiline emphasis' => ["*First\nline* and\n[bad](javascript:alert(1))", 3],
    'after an earlier link' => ["First [safe](/)\nSecond [bad](javascript:alert(1))", 2],
]);

it('rejects a first heading below level one and skipped heading levels', function (string $source): void {
    (void) new Parser()->parse($source, 'structure.md');
})->throws(ContentException::class)->with([
    'first heading is level two' => ["Paragraph.\n\n## Two"],
    'skip from one to three' => ["# One\n\n### Three"],
    'skip after descending' => ["# One\n\n## Two\n\n# One again\n\n### Three"],
]);

it('allows heading levels to remain level or descend without skipping', function (string $source): void {
    expect(new Parser()->parse($source, 'structure.md')->blocks)->not->toBeEmpty();
})->with([
    "# One\n\n# Another",
    "# One\n\n## Two\n\n### Three\n\n# One again\n\n## Two again",
    'No authored heading.',
]);

it('rejects blank link labels with the source line', function (string $label): void {
    (void) new Parser()->parse("Intro.\n\n[{$label}](/target/)", 'blank.md');
})->throws(ContentException::class, "Link label in 'blank.md' must not be blank at line 3.")
    ->with(['', ' ', "\t"]);

it('reports an unclosed fence with its path and opening line', function (): void {
    (void) new Parser()->parse("first\n\n```js\nconst x = 1;", 'content/post/article.md');
})->throws(ContentException::class, "Unclosed code fence in 'content/post/article.md' at line 3.");

it('rejects invalid UTF-8 when parsing directly', function (): void {
    (void) new Parser()->parse("bad\xFF", 'direct.md');
})->throws(ContentException::class, "Article 'direct.md' is not valid UTF-8.");
