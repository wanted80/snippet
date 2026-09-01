<?php

declare(strict_types=1);

use Snippet\Markdown\Parser;
use Snippet\Rendering\MarkdownHtmlRenderer;

mutates(MarkdownHtmlRenderer::class, Parser::class);

it('renders the typed Markdown document with escaping and contextual links', function (): void {
    $markdown = <<<'MARKDOWN'
# Heading

Text <raw>, *emphasis*, **strong**, ~~removed~~, `code`, [web](https://example.test/?a=1&b=2), [fragment](#part), and [asset](file.txt).

---

- item

1. ordered

```php
<&
```
MARKDOWN;
    $document = new Parser()->parse($markdown, 'article.md');

    $html = MarkdownHtmlRenderer::render($document, 2, '/articles/post/');

    expect($html)->toContain(
        '<h3>Heading</h3>',
        '<p>Text &lt;raw&gt;, <em>emphasis</em>, <strong>strong</strong>, <s>removed</s>, <code>code</code>, <a href="https://example.test/?a=1&amp;b=2">web</a>, <a href="#part">fragment</a>, and <a href="/articles/post/file.txt">asset</a>.</p>',
        '<hr>',
        "<ul>\n<li>item</li>\n</ul>",
        "<ol>\n<li>ordered</li>\n</ol>",
        '<pre><code class="language-php">&lt;&amp;</code></pre>',
    )->toEndWith("\n");
});

it('prefixes portable root links while preserving relative, fragment, and external targets', function (): void {
    $document = new Parser()->parse(
        '[root](/about/) [relative](file.txt) [fragment](#part) [external](https://outside.test/path)',
        'page.md',
    );

    expect(MarkdownHtmlRenderer::render($document, basePath: '/snippet'))->toContain(
        '<a href="/snippet/about/">root</a>',
        '<a href="file.txt">relative</a>',
        '<a href="#part">fragment</a>',
        '<a href="https://outside.test/path">external</a>',
    );
});
