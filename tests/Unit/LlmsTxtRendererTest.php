<?php

declare(strict_types=1);

use Snippet\Content\Article;
use Snippet\Content\Catalog;
use Snippet\Content\Page;
use Snippet\Markdown\Parser;
use Snippet\Publishing\LlmsTxtRenderer;
use Snippet\Site\Config;

mutates(LlmsTxtRenderer::class);

it('streams deterministic escaped metadata in catalog order without reading document bodies', function (): void {
    $parser = new Parser();
    $catalog = new Catalog(
        articles: [
            new Article('newest', 'Newest [guide]', "First line\n## injected", '2026-08-04', [], $parser->parse('SECRET ARTICLE BODY', 'newest.md'), []),
            new Article('older', '日本語', 'Café *notes*.', '2026-08-03', [], $parser->parse('OLDER BODY', 'older.md'), []),
        ],
        pages: [
            new Page('about', 'About (us)', "About\tthis site.", $parser->parse('SECRET PAGE BODY', 'about.md'), []),
        ],
    );
    $config = new Config(
        title: 'Test # Site',
        sitename: 'Test Site',
        author: '日本語 *Writer*',
        description: "A personal collection.\n- not a list",
        url: 'https://example.test',
        language: 'en',
        assets: [],
        hasTheme: false,
    );

    $chunks = iterator_to_array(new LlmsTxtRenderer($config, $catalog)->render(), false);
    $document = implode('', $chunks);

    expect($document)->toBe(<<<'TXT'
# Test # Site

> A personal collection. - not a list

Author: 日本語 \*Writer\*

## Articles

- [Newest \[guide\]](https://example.test/articles/newest/): First line ## injected (Published: 2026-08-04)
- [日本語](https://example.test/articles/older/): Café \*notes\*. (Published: 2026-08-03)

## Pages

- [About \(us\)](https://example.test/about/): About this site.
TXT . "\n")
        ->and(array_sum(array_map(static fn(string $chunk): int => mb_strlen($chunk, '8bit'), $chunks)))->toBe(mb_strlen($document, '8bit'))
        ->and($document)->not->toContain('SECRET ARTICLE BODY', 'OLDER BODY', 'SECRET PAGE BODY')
        ->and(implode('', iterator_to_array(new LlmsTxtRenderer($config, $catalog)->render(), false)))->toBe($document);
});

it('omits empty collection sections', function (Catalog $catalog, string $expected): void {
    $config = new Config('Empty', 'Empty', 'Writer', 'Nothing here.', 'https://example.test', 'en', [], false);
    $document = implode('', iterator_to_array(new LlmsTxtRenderer($config, $catalog)->render(), false));

    expect($document)->toBe($expected);
})->with(function (): array {
    $document = new Parser()->parse('Body.', 'content.md');

    return [
        'empty catalog' => [new Catalog([], []), "# Empty\n\n> Nothing here.\n\nAuthor: Writer\n"],
        'articles only' => [new Catalog([new Article('post', 'Post', 'Description.', '2026-01-01', [], $document, [])], []), "# Empty\n\n> Nothing here.\n\nAuthor: Writer\n\n## Articles\n\n- [Post](https://example.test/articles/post/): Description. (Published: 2026-01-01)\n"],
        'pages only' => [new Catalog([], [new Page('page', 'Page', 'Description.', $document, [])]), "# Empty\n\n> Nothing here.\n\nAuthor: Writer\n\n## Pages\n\n- [Page](https://example.test/page/): Description.\n"],
    ];
});

it('escapes every Markdown structural character in metadata', function (string $character, string $escaped): void {
    $config = new Config("X{$character}Y", 'Site', 'Writer', 'Description.', 'https://example.test', 'en', [], false);
    $chunks = iterator_to_array(new LlmsTxtRenderer($config, new Catalog([], []))->render(), false);

    expect($chunks[0])->toBe("# X{$escaped}Y\n\n");
})->with([
    'backslash' => ['\\', '\\\\'],
    'backtick' => ['`', '\\`'],
    'asterisk' => ['*', '\\*'],
    'underscore' => ['_', '\\_'],
    'opening brace' => ['{', '\\{'],
    'closing brace' => ['}', '\\}'],
    'opening bracket' => ['[', '\\['],
    'closing bracket' => [']', '\\]'],
    'less than' => ['<', '\\<'],
    'greater than' => ['>', '\\>'],
    'opening parenthesis' => ['(', '\\('],
    'closing parenthesis' => [')', '\\)'],
    'exclamation mark' => ['!', '\\!'],
    'pipe' => ['|', '\\|'],
    'tilde' => ['~', '\\~'],
]);
