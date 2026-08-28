<?php

declare(strict_types=1);

use Snippet\Content\Catalog;
use Snippet\Content\CatalogLoader;
use Snippet\Content\ContentType;
use Snippet\Exception\ContentException;
use Snippet\Markdown\Paragraph;
use Snippet\Markdown\Parser;
use Snippet\Markdown\Text;
use Snippet\Publishing\Publisher;
use Snippet\Rendering\Template;
use Snippet\Rendering\TemplateLoader;
use Snippet\Rendering\Templates;
use Snippet\Site\ConfigLoader;
use Snippet\Site\Limits;
use Snippet\Support\ApplicationVersion;
use Snippet\Support\TrustedPhpLoader;

mutates(CatalogLoader::class, Parser::class, TemplateLoader::class);

it('accepts menu opt-ins and rejects duplicate orders', function (): void {
    $this->item('about', ['title' => 'About', 'description' => 'D', 'menu_order' => 2]);
    $this->item('contact', ['title' => 'Contact', 'description' => 'D']);
    $catalog = $this->catalog();

    expect($catalog->pages[0]->menuOrder)->toBe(2)
        ->and($catalog->pages[1]->menuOrder)->toBeNull();

    $this->item('work', ['title' => 'Work', 'description' => 'D', 'menu_order' => 2]);
    $this->catalog();
})->throws(ContentException::class, 'duplicate menu_order 2');

it('enforces internal collection limits before parsing an excess item', function (ContentType $type): void {
    $metadata = $type === ContentType::Article
        ? ['title' => 'One', 'description' => 'D', 'date' => '2026-01-01', 'tags' => []]
        : ['title' => 'One', 'description' => 'D'];

    if ($type === ContentType::Article) {
        $this->article('one', $metadata);
    } else {
        $this->item('one', $metadata);
    }

    $metadata['title'] = 'Two';
    if ($type === ContentType::Article) {
        $this->article('two', $metadata, '```');
    } else {
        $this->item('two', $metadata, '```');
    }

    $limits = $type === ContentType::Article ? new Limits(articles: 1) : new Limits(pages: 1);

    expect(fn(): Catalog => new CatalogLoader()->load($this->directory . '/content', $limits))
        ->toThrow(ContentException::class, $type === ContentType::Article ? '1-article limit' : '1-page limit');
})->with([
    'pages' => ContentType::Page,
    'articles' => ContentType::Article,
]);


it('renders deterministic pages navigation, directory order, CSP, and extended Markdown', function (): void {
    $this->content();
    $this->item('zulu', ['title' => 'Alpha', 'description' => 'First.', 'menu_order' => 2], "~~old~~\n\n---");
    $this->item('alpha', ['title' => 'Zulu', 'description' => 'Second.', 'menu_order' => 1]);
    $this->resources();
    $config = new ConfigLoader()->load($this->directory . '/site');
    $catalog = new CatalogLoader()->load($this->directory . '/content');
    new Publisher()->publish($this->directory, $config, $catalog);

    $page = file_get_contents($this->directory . '/public/zulu/index.html');
    $directory = file_get_contents($this->directory . '/public/pages/index.html');
    expect($page)->toBeString()
        ->toContain('<s>old</s>', '<hr>', 'Content-Security-Policy')
        ->toContain('<a class="menu-link" href="/alpha/">Zulu</a>')
        ->toContain('<a class="menu-link" href="/zulu/" aria-current="page">Alpha</a>')
        ->toContain('<a class="menu-link" href="/llms.txt">llms.txt</a>')
        ->toMatch('~>Articles</a>[\s\S]*>Tags</a>[\s\S]*>Pages</a>[\s\S]*>Zulu</a>[\s\S]*>Alpha</a>[\s\S]*>llms\.txt</a>~')
        ->and($directory)->toBeString()
        ->toContain('<a href="/zulu/">Alpha</a>', '<a href="/alpha/">Zulu</a>', '<a class="menu-link" href="/pages/" aria-current="page">Pages</a>')
        ->toMatch('~<ul class="page-list"[\s\S]*Alpha[\s\S]*Zulu~');
});

it('rejects invalid menu orders', function (mixed $order): void {
    $this->item('page', ['title' => 'Page', 'description' => 'D', 'menu_order' => $order], type: ContentType::Page);
    $this->catalog();
})->throws(ContentException::class, 'positive integer')->with([0, '1', true]);

it('enforces each content resource boundary', function (string $boundary, string $message): void {
    $this->content();
    $limits = match ($boundary) {
        'articles' => new Limits(articles: 1),
        'markdown' => new Limits(markdownBytes: 1),
        'catalog markdown' => new Limits(markdownBytes: 2, catalogMarkdownBytes: 3),
        'markdown depth' => new Limits(markdownDepth: 1),
        'document nodes' => new Limits(documentNodes: 1),
        'catalog nodes' => new Limits(documentNodes: 2, catalogNodes: 3),
        'asset depth' => new Limits(assetDepth: 1),
        'asset size' => new Limits(assetBytes: 1),
        'asset aggregate' => new Limits(assetBytes: 2, catalogAssetBytes: 1),
        'asset count' => new Limits(assetsPerItem: 1),
        'catalog assets' => new Limits(catalogAssets: 1),
        'title' => new Limits(titleCharacters: 1),
        'tags' => new Limits(tagsPerArticle: 1),
        'tag characters' => new Limits(tagCharacters: 1),
        'menu pages' => new Limits(),
        default => throw new LogicException("Unknown boundary fixture '{$boundary}'."),
    };

    if ($boundary === 'articles') {
        $this->article('one', ['title' => 'A', 'description' => 'D', 'date' => '2026-01-01', 'tags' => []]);
        $this->article('two', ['title' => 'B', 'description' => 'D', 'date' => '2026-01-01', 'tags' => []]);
    } elseif (in_array($boundary, ['catalog markdown', 'catalog nodes'], true)) {
        $this->item('one', ['title' => 'A', 'description' => 'D'], 'AB');
        $this->item('two', ['title' => 'B', 'description' => 'D'], 'AB');
    } elseif (in_array($boundary, ['tags', 'tag characters'], true)) {
        $tags = $boundary === 'tags' ? ['A', 'B'] : ['AB'];
        $this->article('one', ['title' => 'A', 'description' => 'D', 'date' => '2026-01-01', 'tags' => $tags]);
    } elseif ($boundary === 'menu pages') {
        foreach (range(1, 5) as $order) {
            $this->item('page-' . $order, ['title' => 'P' . $order, 'description' => 'D', 'menu_order' => $order]);
        }
    } else {
        $markdown = match ($boundary) {
            'markdown' => 'AB',
            'markdown depth' => '*~~old~~*',
            default => 'A',
        };
        $path = $this->item('one', ['title' => $boundary === 'title' ? 'AB' : 'A', 'description' => 'D'], $markdown);
        if ($boundary === 'asset depth') {
            mkdir($path . '/one/two', 0777, true);
            file_put_contents($path . '/one/two/a', 'a');
        } elseif ($boundary === 'asset size') {
            file_put_contents($path . '/asset', 'ab');
        } elseif (in_array($boundary, ['asset aggregate', 'asset count', 'catalog assets'], true)) {
            file_put_contents($path . '/one', 'a');
            file_put_contents($path . '/two', 'b');
        }
    }

    expect(fn(): Catalog => new CatalogLoader()->load($this->directory . '/content', $limits))->toThrow(ContentException::class, $message);
})->with([
    'articles' => ['articles', '1-article limit'],
    'markdown' => ['markdown', '1-byte document limit'],
    'catalog markdown' => ['catalog markdown', '3-byte catalog Markdown limit'],
    'markdown depth' => ['markdown depth', 'nested inline depth 1'],
    'document nodes' => ['document nodes', '1-node document limit'],
    'catalog nodes' => ['catalog nodes', '3-node catalog limit'],
    'asset depth' => ['asset depth', 'directory depth 1'],
    'asset size' => ['asset size', '1-byte limit'],
    'asset aggregate' => ['asset aggregate', '1-byte catalog asset limit'],
    'asset count' => ['asset count', '1-asset limit'],
    'catalog assets' => ['catalog assets', '1-asset catalog limit'],
    'title' => ['title', '1-character limit'],
    'tags' => ['tags', '1-tag limit'],
    'tag characters' => ['tag characters', '1-character limit'],
    'menu pages' => ['menu pages', 'No more than 4 pages'],
]);

it('rejects template size boundaries', function (): void {
    $this->resources();
    expect(fn(): Templates => new TemplateLoader()->load($this->directory . '/resources/templates', new Limits(templateBytes: 1)))->toThrow(ContentException::class, 'size limits');
});

it('rejects placeholders in executable or ambiguous template contexts', function (string $unsafe): void {
    $this->resources();
    $layout = $this->directory . '/resources/templates/layout.html';
    $contents = file_get_contents($layout);
    if (!is_string($contents)) {
        throw new LogicException('Unable to read the layout fixture.');
    }
    file_put_contents($layout, str_replace('{{body}}', $unsafe, $contents));
    expect(fn(): Templates => new TemplateLoader()->load($this->directory . '/resources/templates'))->toThrow(ContentException::class, 'executable');
})->with([
    'script body' => '<script>{{body}}</script>',
    'unquoted event attribute' => '<button onload={{body}}>Load</button>',
    'unquoted ordinary attribute' => '<div data-value={{body}}>Value</div>',
    'quoted style attribute' => '<div style="{{body}}">Styled</div>',
]);

it('allows placeholders in ordinary text and quoted attributes containing equals-like text', function (): void {
    $this->resources();
    $layout = $this->directory . '/resources/templates/layout.html';
    $contents = file_get_contents($layout);
    if (!is_string($contents)) {
        throw new LogicException('Unable to read the layout fixture.');
    }

    $contents = str_replace(
        ['lang="{{language}}"', '{{body}}'],
        ['lang="locale={{language}}"', 'example={{body}}'],
        $contents,
    );
    file_put_contents($layout, $contents);

    $templates = new TemplateLoader()->load($this->directory . '/resources/templates');
    $rendered = $templates->render(Template::Layout, [
        'language' => 'en',
        'description' => 'Description',
        'author' => 'Writer',
        'version' => ApplicationVersion::CURRENT,
        'title' => 'Title',
        'canonical' => 'https://example.test/',
        'social_metadata' => '',
        'base_path' => '',
        'preloads' => '',
        'site_stylesheet' => '',
        'site_script' => '',
        'sitename' => 'Brand',
        'navigation' => '',
        'body' => 'Body',
    ]);

    expect($rendered)->toContain('lang="locale=en"', 'example=Body');
});

it('rejects declarative PHP token edge cases without evaluation', function (string $source, string $message): void {
    $path = $this->directory . '/literal.php';
    file_put_contents($path, $source);
    expect(fn(): array => new TrustedPhpLoader()->load($path, 'literal'))->toThrow(ContentException::class, $message);
})->with([
    'parse error' => ["<?php declare(strict_types=1); return [;", 'parse'],
    'wrong declaration name' => ["<?php declare(ticks=1); return [];", 'literal array'],
    'wrong strict value' => ["<?php declare(strict_types=0); return [];", 'literal array'],
    'non-array return' => ["<?php declare(strict_types=1); return 1;", 'literal array'],
    'duplicate key' => ["<?php declare(strict_types=1); return ['a' => 1, 'a' => 2];", 'duplicate array key'],
    'constant' => ["<?php declare(strict_types=1); return ['a' => UNKNOWN];", 'literal array'],
    'leading zero' => ["<?php declare(strict_types=1); return ['a' => 01];", 'literal array'],
    'numeric outer keys' => ["<?php declare(strict_types=1); return [0 => 'a'];", 'literal array'],
    'invalid key type' => ["<?php declare(strict_types=1); return [null => 1];", 'literal array'],
    'alternate declaration' => ["<?php declare(strict_types=1): enddeclare; return [];", 'literal array'],
    'overflowing integer' => ["<?php declare(strict_types=1); return ['value' => 9223372036854775808];", 'literal array'],
    'implicit key after maximum integer' => ["<?php declare(strict_types=1); return ['value' => [9223372036854775807 => true, false]];", 'literal array'],
    'lowest surrogate escape' => ['<?php declare(strict_types=1); return [\'value\' => "\\u{D800}"];', 'literal array'],
    'highest surrogate escape' => ['<?php declare(strict_types=1); return [\'value\' => "\\u{DFFF}"];', 'literal array'],
]);

it('decodes literal values with PHP semantics without executing the file', function (): void {
    $path = $this->directory . '/literal.php';
    file_put_contents($path, <<<'PHP'
<?php
/* config */ declare(strict_types=1);
return [
    'value' => ["\n\r\t\v\f\0\7\377\x41\u{D7FF}\u{E000}\u{10FFFF}", 5 => "\u{1F600}\e\q\$\101\x42", true, false, null],
];
?>
PHP);
    $value = new TrustedPhpLoader()->load($path, 'literal');

    expect($value)->toBe(['value' => ["\n\r\t\v\f\0\7\377A\u{D7FF}\u{E000}\u{10FFFF}", 5 => "😀\x1B\\q\$AB", 6 => true, 7 => false, 8 => null]]);
});

it('rejects an oversized declarative file before tokenization', function (): void {
    $path = $this->directory . '/literal.php';
    file_put_contents($path, "<?php declare(strict_types=1); return [];\n");
    expect(fn(): array => new TrustedPhpLoader()->load($path, 'literal', 1))->toThrow(ContentException::class, '1-byte');
});

it('keeps incomplete strikethrough delimiters literal', function (string $markdown): void {
    $document = new Parser()->parse($markdown, 'x.md');
    $paragraph = $document->blocks[0];
    assert($paragraph instanceof Paragraph);
    $inlines = iterator_to_array($document->inlines($paragraph), false);

    expect($inlines)->toEqual([new Text(0, mb_strlen($markdown, '8bit'))])
        ->and($document->source)->toBe($markdown);
})->with(['single tilde' => '~one', 'empty pair' => '~~~~']);
