<?php

declare(strict_types=1);

use Snippet\Application;
use Snippet\Content\Catalog;
use Snippet\Content\CatalogLoader;
use Snippet\Content\Page;
use Snippet\Exception\ContentException;
use Snippet\Markdown\Block;
use Snippet\Markdown\Document;
use Snippet\Markdown\InlineArena;
use Snippet\Markdown\Parser;
use Snippet\Publishing\CssMinifier;
use Snippet\Publishing\HtmlMinifier;
use Snippet\Publishing\LlmsTxtRenderer;
use Snippet\Publishing\Publisher;
use Snippet\Publishing\ReferenceValidator;
use Snippet\Rendering\HtmlRenderer;
use Snippet\Rendering\MarkdownHtmlRenderer;
use Snippet\Rendering\TemplateLoader;
use Snippet\Site\Config;
use Snippet\Site\ConfigLoader;
use Snippet\Site\Limits;
use Snippet\Support\ApplicationVersion;
use Snippet\Tests\PublisherFaults;

mutates(CatalogLoader::class, CssMinifier::class, HtmlMinifier::class, LlmsTxtRenderer::class, MarkdownHtmlRenderer::class, Parser::class, ReferenceValidator::class, TemplateLoader::class);

/** @return array<string, string> */
function publicationBytes(string $root): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/public', FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        assert($file instanceof SplFileInfo);
        if ($file->isFile()) {
            $contents = file_get_contents($file->getPathname());
            assert(is_string($contents));
            $files[mb_substr($file->getPathname(), mb_strlen($root . '/public/'))] = $contents;
        }
    }

    ksort($files);
    return $files;
}

it('builds the complete deterministic site with escaped semantic HTML and every asset class', function (): void {
    $this->content();
    $articlePath = $this->article('post', [
        'title' => 'Post <one>',
        'description' => 'Article & description.',
        'date' => '2026-08-04',
        'tags' => [
            'PHP & Web',
            'Design',
            '日本語',
        ],
        'cover' => true,
        'alt' => 'Cover.',
    ], "# Heading <raw>\n\nText *em* **strong** `code` [link](https://example.test/?a=1&b=2) and [asset](files/deep/data.bin).\n\n- item\n\n1. ordered\n\n```php\n<&\n```");
    mkdir($articlePath . '/files/deep', 0777, true);
    file_put_contents($articlePath . '/files/deep/data.bin', "\0\xFF");
    $this->image($articlePath . '/cover.webp');
    $pagePath = $this->item('about', ['title' => 'About', 'description' => 'About page.', 'menu_order' => 1], 'About body.');
    file_put_contents($pagePath . '/note.txt', 'page asset');
    $this->article('untagged', ['title' => 'Untagged', 'description' => 'No tags.', 'date' => '2026-08-03', 'tags' => []], 'Plain.');

    mkdir($this->directory . '/site/assets/media', 0777, true);
    file_put_contents($this->directory . '/site/assets/media/custom.bin', "asset\0");
    file_put_contents($this->directory . '/site/theme.css', '@layer overrides { :root { --color-accent: #fff; } }');
    $this->site(['title' => 'Brand & Co', 'sitename' => 'Snippet']);
    $this->resources();
    $layoutPath = $this->directory . '/resources/templates/layout.html';
    $layout = file_get_contents($layoutPath);
    assert(is_string($layout));
    file_put_contents($layoutPath, str_replace('Published with Snippet.', 'Handcrafted publishing.', $layout));

    $config = new ConfigLoader()->load($this->directory . '/site');
    $catalog = new CatalogLoader()->load($this->directory . '/content');
    new Publisher()->publish($this->directory, $config, $catalog);
    $first = publicationBytes($this->directory);
    new Publisher()->publish($this->directory, $config, $catalog);

    expect(array_keys($first))->toBe([
        'about/index.html',
        'about/note.txt',
        'articles/index.html',
        'articles/post/cover.webp',
        'articles/post/files/deep/data.bin',
        'articles/post/index.html',
        'articles/untagged/index.html',
        'assets/site.css',
        'assets/site/media/custom.bin',
        'assets/theme.css',
        'assets/theme.js',
        'index.html',
        'llms.txt',
        'pages/index.html',
        'tags/design/index.html',
        'tags/index.html',
        'tags/php-web/index.html',
        'tags/日本語/index.html',
    ])->and(publicationBytes($this->directory))->toBe($first);

    $article = $first['articles/post/index.html'];
    $untagged = $first['articles/untagged/index.html'];
    $home = $first['index.html'];
    $articlesIndex = $first['articles/index.html'];
    $pagesIndex = $first['pages/index.html'];
    $tagsIndex = $first['tags/index.html'];
    $tagArchive = $first['tags/php-web/index.html'];
    $multilingualTagArchive = $first['tags/日本語/index.html'];
    $css = $first['assets/site.css'];
    $llms = $first['llms.txt'];
    expect($llms)->toBe(<<<'TXT'
# Brand & Co

> A test site.

Author: Test Author

## Articles

- [Post \<one\>](https://example.test/articles/post/): Article & description. (Published: 2026-08-04)
- [Untagged](https://example.test/articles/untagged/): No tags. (Published: 2026-08-03)

## Pages

- [About](https://example.test/about/): About page.
TXT . "\n")
        ->and($article)->toContain('<title>Post &lt;one&gt; — Brand &amp; Co</title>')
        ->toContain('<meta name="generator" content="Snippet ' . ApplicationVersion::CURRENT . '">')
        ->toContain('<link rel="canonical" href="https://example.test/articles/post/">')
        ->toContain('<article class="content-article">')
        ->toMatch('~<link rel="stylesheet" href="/assets/site\.css">\s+<link rel="stylesheet" href="/assets/theme\.css">~')
        ->toContain("style-src 'self'", "font-src 'self'", "img-src 'self'")->not->toContain('fonts.bunny.net', 'frame-ancestors')
        ->toContain('<footer class="site-footer">', '<p class="site-footer-row">', '<span class="site-footer-heart" aria-hidden="true">♥</span>', '<a href="https://github.com/wanted80/snippet"><svg class="site-footer-github"')
        ->toContain('<h1>Post &lt;one&gt;</h1>')
        ->toContain('<figure class="article-figure">', '<img src="/articles/post/cover.webp" alt="Cover." width="1" height="1">')
        ->toContain('<span class="tag-label">PHP &amp; Web</span>')
        ->toContain('<h2>Heading &lt;raw&gt;</h2>')
        ->toContain('<em>em</em>', '<strong>strong</strong>', '<code>code</code>')
        ->toContain('<a href="files/deep/data.bin">asset</a>')
        ->toContain('<a href="https://example.test/?a=1&amp;b=2">link</a>')
        ->toContain("<ul>\n<li>item</li>\n</ul>", "<ol>\n<li>ordered</li>\n</ol>")
        ->toContain('<pre><code class="language-php">&lt;&amp;</code></pre>')
        ->toContain('<time datetime="2026-08-04">August 4, 2026</time>')
        ->and(mb_substr_count($article, '<h1>'))->toBe(1)
        ->and(mb_substr_count($article, 'rel="canonical"'))->toBe(1)
        ->and($first['about/index.html'])->toContain('aria-current="page"', '<article class="content-page">')
        ->and($tagArchive)->toContain('<p class="eyebrow">Topic</p>', '<h1>PHP &amp; Web</h1>', '<p>Articles tagged PHP &amp; Web.</p>', 'Post &lt;one&gt;')
        ->toMatch('~<section aria-label="Articles">\s*<ul class="article-list">\s*<li>\s*<article>~')
        ->and($multilingualTagArchive)->toContain('<p class="eyebrow">Topic</p>', '<h1>日本語</h1>', '<p>Articles tagged 日本語.</p>')
        ->and($articlesIndex)->toMatch('~<section aria-label="Articles">\s*<ul class="article-list">\s*<li>\s*<article>~')
        ->and($pagesIndex)->toMatch('~<section aria-label="Pages">\s*<ul class="page-list">\s*<li>\s*<article>~')
        ->and($tagsIndex)->toMatch('~<section aria-label="Tags">\s*<ul class="tag-grid tag-index">\s*<li>~')
        ->and($home)->toContain('<h1 class="visually-hidden">Brand &amp; Co</h1>', 'class="featured-article"')
        ->toContain('<header class="content-header">')
        ->toContain('<figure class="article-figure">', '<img src="/articles/post/cover.webp" alt="Cover." width="1" height="1">')
        ->toContain('<p class="eyebrow">Latest article</p>', '<h2><a href="/articles/post/">Post &lt;one&gt;</a></h2>')
        ->toContain('<h3>Heading &lt;raw&gt;</h3>', '<em>em</em>', '<strong>strong</strong>', '<a href="/articles/post/files/deep/data.bin">asset</a>')
        ->toContain('<section class="home-archive"', '<h2 id="more-articles">More articles</h2>')
        ->toMatch('~<ul class="archive-list">\s*<li>\s*<article>~')
        ->toContain('<h3><a href="/articles/untagged/">Untagged</a></h3>', '<time datetime="2026-08-03">August 3, 2026</time>')
        ->toContain('<section class="home-tags"', '<h2 id="browse-tags">Browse by tag</h2>')
        ->toMatch('~<ul class="tag-grid">\s*<li>~')
        ->toContain('<a href="/tags/php-web/" aria-label="PHP &amp; Web, 1 article">', '<span class="tag-count" aria-hidden="true">1</span>')
        ->toContain('<a class="menu-link" href="/articles/">Articles</a>', '<a class="menu-link" href="/tags/">Tags</a>')
        ->toContain('<a class="menu-link" href="/pages/">Pages</a>', '<a class="menu-link" href="/about/">About</a>')
        ->toMatch('~>Articles</a>[\s\S]*>Tags</a>[\s\S]*>Pages</a>[\s\S]*>About</a>~')->not->toContain('Article &amp; description.')
        ->and($untagged)->not->toContain('<ul class="tag-list">')
        ->and($css)->toContain('--color-background: #08090a;', '--color-background-glass: rgb(8 9 10 / 82%);', '--color-background-glass: rgb(247 241 232 / 82%);', '::selection', 'scrollbar-color:')
        ->toContain('min-inline-size: 320px;')
        ->toContain('--color-interactive: #171d22;', '--color-interactive: #eee3d8;', '--space-section: clamp(5rem, 12vw, 7rem);')
        ->toContain('--font-reading: ui-serif, Charter, "Bitstream Charter", "Sitka Text", Cambria, Georgia, serif;')
        ->toContain('display: grid;', 'grid-template-rows: auto 1fr auto;')
        ->toContain('position: sticky;', '.site-header[data-scrolled]', '@supports ((backdrop-filter: blur(1px)) or (-webkit-backdrop-filter: blur(1px)))')
        ->toContain('@media (prefers-reduced-transparency: reduce)')
        ->toMatch('/@media \(prefers-reduced-motion: no-preference\)\s*\{\s*\.site-header\s*\{[^}]*transition: background-color 180ms ease, box-shadow 180ms ease;\s*\}\s*:root\[data-theme-changing\]\s*\.site-header\s*\{\s*transition: none;\s*\}\s*\}/s')
        ->toContain('--measure-prose: 44rem;', '--measure-shell: 44rem;', '--radius-control: 0.75rem;')
        ->toContain('inline-size: min(100% - 2rem, var(--measure-shell));', 'margin-inline: auto;')
        ->toContain('inline-size: min(100% - 1rem, calc(var(--measure-shell) + 1rem));')
        ->toContain('--shadow-header: 0 0.25rem 0.9rem rgb(0 0 0 / 22%);', '--shadow-header: 0 0.25rem 0.9rem rgb(77 52 34 / 10%);')
        ->toMatch('/\.site-header\[data-scrolled\]\s*\{[^}]*background: var\(--color-surface\)/s')
        ->toMatch('/\.site-header\[data-scrolled\]\s*\{[^}]*box-shadow: var\(--shadow-header\)/s')
        ->toMatch('/@supports \(\(backdrop-filter: blur\(1px\)\) or \(-webkit-backdrop-filter: blur\(1px\)\)\)\s*\{\s*\.site-header\[data-scrolled\]\s*\{[^}]*\}\s*\.site-navigation\s*\{[^}]*background: var\(--color-background-glass\);[^}]*backdrop-filter: saturate\(140%\) blur\(1rem\);[^}]*\}\s*\}/s')
        ->toMatch('/\.site-footer\s*\{[^}]*text-align: center;/s')
        ->toMatch('/\.site-footer-row\s*\{[^}]*display: inline-flex;[^}]*align-items: center;[^}]*flex-wrap: wrap;/s')
        ->toMatch('/\.site-footer-link a\s*\{[^}]*display: inline-flex;[^}]*align-items: center;/s')
        ->toMatch('/\.site-navigation\s*\{[^}]*inset-inline: max\(1rem, calc\(\(100vi - var\(--measure-shell\)\) \/ 2\)\) auto/s')
        ->toMatch('/\.icon-button\s*\{[^}]*border-radius: var\(--radius-control\)/s')
        ->toMatch('/\.archive-list\s*\{[^}]*display: grid;[^}]*gap: var\(--space-4\)/s')
        ->toMatch('/\.tag-grid \.tag-count\s*\{[^}]*background: var\(--color-accent\);[^}]*color: var\(--color-background\);[^}]*font-weight: 700/s')
        ->toMatch('/\.prose hr\s*\{[^}]*border: 0/s')
        ->toMatch('/\.skip-link\s*\{[^}]*z-index: 20/s')
        ->toContain('::-webkit-scrollbar')
        ->toContain('@media (max-width: 40rem)')
        ->toMatch('/@media \(max-width: 40rem\)[\s\S]*\.site-navigation\s*\{[^}]*inset-inline: 0\.75rem/s')
        ->toContain('font-size: 0.9rem;', 'font-size: 0.9em;')
        ->toMatch('/\.tag-label\s*\{[^}]*display: block;[^}]*min-inline-size: 0;[^}]*overflow: hidden;[^}]*text-overflow: ellipsis;[^}]*white-space: nowrap/s')
        ->toMatch('/\.tag-list a\s*\{[^}]*max-inline-size: 100%/s')
        ->toMatch('/\.prose pre\s*\{[^}]*max-inline-size: 100%;[^}]*overflow-x: auto;[^}]*padding: var\(--space-3\);[^}]*border: 1px solid var\(--color-border\);[^}]*border-radius: var\(--radius-control\);[^}]*background: var\(--color-background\);/s')
        ->toMatch('/@media \(hover: hover\) and \(pointer: fine\)[\s\S]*\.icon-button:hover/s');
});

it('fails explicitly if an unknown Markdown block reaches rendering', function (): void {
    $block = new class implements Block {};
    $document = new Document('', [$block], [], new InlineArena('', 0));
    $page = new Page('page', 'Page', 'Description', $document, []);
    $config = new Config('Site', 'Site', 'Test Author', 'Description', 'https://example.test', 'en', [], false);
    $this->resources();
    $templates = new TemplateLoader()->load($this->directory . '/resources/templates');
    new HtmlRenderer($config, new Catalog([], []), $templates)->content($page);
})->throws(LogicException::class, 'Unsupported Markdown block');

it('builds an empty catalog through the CLI', function (): void {
    $this->content();
    $this->resources();
    $stdout = new SplFileObject('php://memory', 'w+');
    $stderr = new SplFileObject('php://memory', 'w+');

    $status = new Application($this->directory)->run(['bin/snippet', 'build'], $stdout, $stderr);
    $stdout->rewind();
    $stderr->rewind();

    expect($status)->toBe(0)
        ->and($stdout->fgets())->toBe("Built site: 0 items.\n")
        ->and($stderr->fgets())->toBeEmpty()
        ->and(file_get_contents($this->directory . '/public/index.html'))->toContain('No articles have been published yet.')
        ->and(file_get_contents($this->directory . '/public/llms.txt'))->toBe("# Test Site\n\n> A test site.\n\nAuthor: Test Author\n")
        ->and($this->directory . '/public/articles')->toBeDirectory()
        ->and($this->directory . '/public/tags')->toBeDirectory();
});

it('preserves an existing publication when a pre-promotion copy fails', function (): void {
    $this->content();
    $path = $this->item('post', ['title' => 'Post', 'description' => 'D']);
    file_put_contents($path . '/asset.txt', 'asset');
    $this->resources();
    $config = new ConfigLoader()->load($this->directory . '/site');
    $catalog = new CatalogLoader()->load($this->directory . '/content');
    mkdir($this->directory . '/public');
    file_put_contents($this->directory . '/public/index.html', 'old publication');
    unlink($path . '/asset.txt');

    try {
        new Publisher()->publish($this->directory, $config, $catalog);
        throw new LogicException('Expected asset copying to fail.');
    } catch (ContentException $contentException) {
        expect($contentException->getMessage())->toContain('Unable to copy');
    }

    expect(publicationBytes($this->directory))->toBe(['index.html' => 'old publication'])
        ->and(glob($this->directory . '/.snippet-*'))->toBe([]);
});

it('enforces publication resource ceilings before replacing the current site', function (string $boundary, string $message): void {
    $this->content();
    $this->resources();
    $config = new ConfigLoader()->load($this->directory . '/site');
    $catalog = new CatalogLoader()->load($this->directory . '/content');
    mkdir($this->directory . '/public');
    file_put_contents($this->directory . '/public/index.html', 'old publication');

    $limits = match ($boundary) {
        'asset' => new Limits(assetBytes: 1),
        'page' => new Limits(renderedPageBytes: 1),
        'build' => new Limits(buildBytes: 1),
        default => throw new LogicException("Unknown publication boundary '{$boundary}'."),
    };

    $publisher = new Publisher();

    $templates = $boundary === 'asset'
        ? new TemplateLoader()->load($this->directory . '/resources/templates')
        : null;
    expect(fn() => $publisher->publish($this->directory, $config, $catalog, $limits, $templates))->toThrow(ContentException::class, $message)
        ->and(publicationBytes($this->directory))->toBe(['index.html' => 'old publication'])
        ->and(glob($this->directory . '/.snippet-*'))->toBe([]);
})->with([
    'asset bytes' => ['asset', '1-byte asset limit'],
    'rendered page bytes' => ['page', '1-byte rendered page limit'],
    'build bytes' => ['build', '1-byte total build limit'],
]);

it('rejects a symlink or non-directory publication target without changing it', function (string $kind): void {
    $this->content();
    $this->resources();
    if ($kind === 'symlink') {
        symlink($this->directory . '/site', $this->directory . '/public');
    } else {
        file_put_contents($this->directory . '/public', 'old');
    }

    $config = new ConfigLoader()->load($this->directory . '/site');
    $catalog = new CatalogLoader()->load($this->directory . '/content');
    new Publisher()->publish($this->directory, $config, $catalog);
})->throws(ContentException::class, 'regular directory')->with(['symlink', 'file']);

it('reports transactional publication and cleanup failures deterministically', function (array $faults, bool $existing, string $message): void {
    /** @var array<string, list<'fail'|'partial'|'pass'|'throw'>> $faults */
    $this->content();
    $this->resources();
    if ($existing) {
        mkdir($this->directory . '/public');
        file_put_contents($this->directory . '/public/index.html', 'old');
    }

    foreach ($faults as $operation => $outcomes) {
        PublisherFaults::set($operation, $outcomes);
    }

    $config = new ConfigLoader()->load($this->directory . '/site');
    $catalog = new CatalogLoader()->load($this->directory . '/content');
    new Publisher()->publish($this->directory, $config, $catalog);
})->throws(ContentException::class)->with([
    'unexpected write exception' => [[
        'file_put_contents' => ['throw'],
    ], false, 'Injected file_put_contents failure'],
    'generated write' => [[
        'file_put_contents' => ['fail'],
    ], false, 'Unable to write generated file'],
    'partial generated write' => [[
        'file_put_contents' => ['partial'],
    ], false, 'Unable to write generated file'],
    'llms stream open' => [[
        'publishing_fopen' => ['fail'],
    ], false, 'Unable to write generated file'],
    'llms stream write' => [[
        'publishing_fwrite' => ['fail'],
    ], false, 'Unable to write generated file'],
    'llms chmod' => [[
        'chmod' => ['pass', 'pass', 'pass', 'pass', 'pass', 'pass', 'pass', 'pass', 'fail'],
    ], false, 'Unable to write generated file'],
    'directory creation' => [[
        'mkdir' => ['fail'],
    ], false, 'Unable to create output directory'],
    'asset size read' => [[
        'filesize' => ['pass', 'pass', 'fail'],
    ], false, 'Unable to read publication asset size'],
    'existing backup' => [[
        'rename' => ['fail'],
    ], true, 'Unable to move existing publication'],
    'promotion without existing output' => [[
        'rename' => ['fail'],
    ], false, 'existing publication was preserved'],
    'promotion with successful rollback' => [[
        'rename' => ['pass', 'fail', 'pass'],
    ], true, 'existing publication was preserved'],
    'promotion with failed rollback' => [[
        'rename' => ['pass', 'fail', 'fail'],
    ], true, 'unable to restore existing publication'],
    'temporary file cleanup' => [[
        'copy' => ['fail'],
        'unlink' => ['fail'],
    ], false, 'Unable to remove temporary path'],
    'temporary directory scan' => [[
        'copy' => ['fail'],
        'scandir' => ['fail'],
    ], false, 'Unable to read temporary directory'],
    'temporary directory removal' => [[
        'copy' => ['fail'],
        'rmdir' => ['fail'],
    ], false, 'Unable to remove temporary directory'],
]);

it('preserves the current publication when minified stylesheet publication fails', function (array $faults, bool $removeStylesheet, string $message): void {
    /** @var array<string, list<'fail'|'pass'|'throw'>> $faults */
    $this->content();
    $this->resources();
    $this->site(['build' => ['minify' => true]]);
    $config = new ConfigLoader()->load($this->directory . '/site');
    $catalog = new CatalogLoader()->load($this->directory . '/content');
    $publisher = new Publisher();
    $templates = $publisher->validate($this->directory, $config);
    mkdir($this->directory . '/public');
    file_put_contents($this->directory . '/public/index.html', 'old publication');

    if ($removeStylesheet) {
        unlink($this->directory . '/resources/site.css');
    }

    foreach ($faults as $operation => $outcomes) {
        PublisherFaults::set($operation, $outcomes);
    }

    expect(fn() => $publisher->publish($this->directory, $config, $catalog, templates: $templates))
        ->toThrow(ContentException::class, $message)
        ->and(publicationBytes($this->directory))->toBe(['index.html' => 'old publication'])
        ->and(glob($this->directory . '/.snippet-*'))->toBe([]);
})->with([
    'missing source' => [[], true, 'Unable to minify'],
    'input open' => [[
        'publishing_fopen' => ['pass', 'fail'],
    ], false, 'Unable to minify'],
    'output open' => [[
        'publishing_fopen' => ['pass', 'pass', 'fail'],
    ], false, 'Unable to minify'],
    'output chmod' => [[
        'chmod' => ['pass', 'pass', 'pass', 'pass', 'pass', 'pass', 'pass', 'pass', 'pass', 'pass', 'fail'],
    ], false, 'Unable to minify'],
]);

it('builds complete indexes and truncates homepage collections at configured boundaries', function (): void {
    $this->content();
    $this->article('newest', ['title' => 'Newest', 'description' => 'Newest description.', 'date' => '2026-08-04', 'tags' => ['Alpha', 'Beta']], 'Newest body.');
    $this->article('second', ['title' => 'Second', 'description' => 'Second description.', 'date' => '2026-08-03', 'tags' => ['Alpha']], 'Second body.');
    $this->article('third', ['title' => 'Third', 'description' => 'Third description.', 'date' => '2026-08-02', 'tags' => ['Beta', 'Gamma']], 'Third body.');
    $this->article('oldest', ['title' => 'Oldest', 'description' => 'Oldest description.', 'date' => '2026-08-01', 'tags' => ['Delta']], 'Oldest body.');
    $this->site(['home' => ['articles' => 1, 'tags' => 2]]);
    $this->resources();

    $config = new ConfigLoader()->load($this->directory . '/site');
    new Publisher()->publish($this->directory, $config, $this->catalog());
    $files = publicationBytes($this->directory);
    $home = $files['index.html'];
    $articles = $files['articles/index.html'];
    $tags = $files['tags/index.html'];

    expect($home)->toContain('Newest', 'Second', 'View all articles', 'Alpha', 'Beta', 'View all tags')
        ->toContain('<a class="button-link" href="/articles/">View all articles<svg class="button-arrow" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5 12h14m-6-6 6 6-6 6"></path></svg></a>', '<a class="button-link" href="/tags/">View all tags<svg class="button-arrow" viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="M5 12h14m-6-6 6 6-6 6"></path></svg></a>')
        ->toContain('<span class="tag-count" aria-hidden="true">2</span>')
        ->not->toContain('Third', 'Gamma', 'Delta')
        ->and($articles)->toContain('Newest description.', 'Second description.', 'Third description.', 'Oldest description.')
        ->toMatch('~Newest[\s\S]*Second[\s\S]*Third[\s\S]*Oldest~')
        ->and($tags)->toMatch('~aria-label="Alpha, 2 articles"[\s\S]*>2</span>[\s\S]*aria-label="Beta, 2 articles"[\s\S]*>2</span>[\s\S]*aria-label="Delta, 1 article"[\s\S]*>1</span>[\s\S]*aria-label="Gamma, 1 article"[\s\S]*>1</span>~')
        ->and($home)->not->toContain('href="/articles/" aria-current', 'href="/tags/" aria-current');
});

it('omits homepage index links when configured collections are not truncated', function (): void {
    $this->article('only', ['title' => 'Only', 'description' => 'D', 'date' => '2026-08-04', 'tags' => ['One']]);
    $this->resources();

    $config = new ConfigLoader()->load($this->directory . '/site');
    new Publisher()->publish($this->directory, $config, $this->catalog());

    expect(file_get_contents($this->directory . '/public/index.html'))->not->toContain('View all articles', 'View all tags');
});

it('hides the homepage grid when no secondary collection exists', function (): void {
    $this->article('only', ['title' => 'Only', 'description' => 'D', 'date' => '2026-08-04', 'tags' => []]);
    $this->resources();

    $config = new ConfigLoader()->load($this->directory . '/site');
    new Publisher()->publish($this->directory, $config, $this->catalog());

    expect(file_get_contents($this->directory . '/public/index.html'))
        ->toContain('<div class="home-grid home-grid-empty">')
        ->not->toContain('home-archive', 'home-tags')
        ->and(file_get_contents($this->directory . '/public/assets/site.css'))
        ->toMatch('/\.home-grid-empty\s*\{[^}]*display: none;/s');
});

it('generates useful empty collection pages', function (): void {
    $this->content();
    $this->resources();

    $config = new ConfigLoader()->load($this->directory . '/site');
    new Publisher()->publish($this->directory, $config, $this->catalog());

    expect(file_get_contents($this->directory . '/public/articles/index.html'))->toContain('No articles have been published yet.')
        ->and(file_get_contents($this->directory . '/public/pages/index.html'))->toContain('No pages have been published yet.')
        ->and(file_get_contents($this->directory . '/public/tags/index.html'))->toContain('No tags are available yet.');
});


it('ships a storage-safe system-aware theme script as a dedicated asset', function (): void {
    $this->content();
    $this->resources();

    $config = new ConfigLoader()->load($this->directory . '/site');
    new Publisher()->publish($this->directory, $config, $this->catalog());
    $html = file_get_contents($this->directory . '/public/index.html');
    $script = file_get_contents($this->directory . '/public/assets/theme.js');
    assert(is_string($html));
    assert(is_string($script));

    expect(mb_substr_count($html, '<script src="/assets/theme.js"></script>'))->toBe(1)
        ->and($html)->toContain("script-src 'self'")
        ->toContain('<header class="site-header" data-site-header>', '<script src="/assets/theme.js"></script>')
        ->toMatch('~<meta charset="utf-8">.*<meta http-equiv="Content-Security-Policy".*<meta name="theme-color" content="#08090a">.*<script src="/assets/theme.js"></script>.*<link rel="stylesheet" href="/assets/site.css">~s')
        ->toContain('<button class="menu-toggle icon-button" type="button" popovertarget="site-navigation" aria-expanded="false" aria-controls="site-navigation" aria-label="Open navigation" title="Open navigation">')
        ->toContain('<button class="theme-toggle icon-button" type="button" data-theme-toggle aria-label="Toggle color theme" title="Toggle color theme">')
        ->toContain('<svg class="menu-icon theme-icon-light"', '<svg class="menu-icon theme-icon-dark"')
        ->toContain('<meta name="theme-color" content="#08090a">')->not->toContain('sha256-', "'unsafe-inline'", '<script>')
        ->and($script)->toContain("'snippet-theme'", "matchMedia('(prefers-color-scheme: light)')", 'storage.getItem', 'storage.setItem', "themeButton.setAttribute('aria-label', label)")
        ->toContain("if (header !== null) {", "if (themeButton === null || themeColor === null) {")
        ->and($script)->toContain("if (root.dataset.theme !== theme) {", "root.dataset.themeChanging = 'true';", 'window.requestAnimationFrame', 'delete root.dataset.themeChanging;', 'const sequence = ++themeChangeSequence;', 'themeChangeSequence === sequence')
        ->toContain("window.addEventListener('storage'", 'event.storageArea !== storage', 'event.key !== storageKey && event.key !== null', 'preference ?? systemTheme()')
        ->toContain("navigation.addEventListener('toggle'", "menuButton.setAttribute('aria-expanded', open ? 'true' : 'false')")
        ->toContain("navigation.addEventListener('keydown'", "event.key === 'Escape'", 'navigation.hidePopover()', 'menuButton.focus()')
        ->toContain("case 'ArrowDown':", "case 'ArrowUp':", "case 'Home':", "case 'End':")
        ->toContain("document.querySelector('[data-site-header]')", "header.toggleAttribute('data-scrolled', scrolled)", "window.addEventListener('scroll', syncScrollState, { passive: true })")
        ->and($script)->toBe(file_get_contents($this->directory . '/resources/theme.js'))
        ->and(mb_substr_count($script, 'window.requestAnimationFrame'))->toBe(2);
});

it('preloads each bundled upright font only when the theme and matching asset are available', function (bool $theme, bool $upright, bool $wordmark, array $expectedAssets): void {
    /** @var list<string> $expectedAssets */
    $this->content();
    $this->resources();
    if ($theme) {
        file_put_contents($this->directory . '/site/theme.css', '@layer overrides {}');
    }
    if ($upright) {
        $fontDirectory = $this->directory . '/site/assets/fonts/atkinson-hyperlegible-next';
        mkdir($fontDirectory, 0777, true);
        file_put_contents($fontDirectory . '/atkinson-hyperlegible-next-variable.woff2', 'wOF2');
        file_put_contents($fontDirectory . '/atkinson-hyperlegible-next-italic-variable.woff2', 'wOF2');
    }
    if ($wordmark) {
        $fontDirectory = $this->directory . '/site/assets/fonts/snippet-logo';
        mkdir($fontDirectory, 0777, true);
        file_put_contents($fontDirectory . '/snippet-logo.woff2', 'wOF2');
    }

    $config = new ConfigLoader()->load($this->directory . '/site');
    new Publisher()->publish($this->directory, $config, $this->catalog());
    $html = file_get_contents($this->directory . '/public/index.html');
    assert(is_string($html));

    $expectedPreloads = array_map(
        static fn(string $asset): string => '<link rel="preload" href="/assets/site/' . $asset . '" as="font" type="font/woff2" crossorigin>',
        $expectedAssets,
    );
    $count = preg_match_all('~<link rel="preload" href="[^"]+" as="font" type="font/woff2" crossorigin>~', $html, $matches);
    assert(is_int($count));
    expect($matches[0])->toBe($expectedPreloads);

    $stylesheet = mb_strpos($html, '<link rel="stylesheet" href="/assets/site.css">');
    assert(is_int($stylesheet));
    foreach ($expectedPreloads as $preload) {
        $position = mb_strpos($html, $preload);
        assert(is_int($position));
        expect($position)->toBeLessThan($stylesheet);
    }
})->with([
    'theme and both fonts' => [true, true, true, ['fonts/atkinson-hyperlegible-next/atkinson-hyperlegible-next-variable.woff2', 'fonts/snippet-logo/snippet-logo.woff2']],
    'theme and interface font' => [true, true, false, ['fonts/atkinson-hyperlegible-next/atkinson-hyperlegible-next-variable.woff2']],
    'theme and wordmark font' => [true, false, true, ['fonts/snippet-logo/snippet-logo.woff2']],
    'theme without fonts' => [true, false, false, []],
    'fonts without theme' => [false, true, true, []],
]);

it('minifies generated HTML and first-party CSS while preserving copied assets', function (): void {
    $this->item('page', ['title' => 'Page', 'description' => 'D'], "Text  with *inline* spacing.\n\n```js\nconst  value = '<tag>';\n```");
    $theme = "@layer overrides {\n    :root { --custom:  one; }\n}\n";
    $arbitraryCss = "custom { bytes:  unchanged; }\n";
    mkdir($this->directory . '/site/assets');
    file_put_contents($this->directory . '/site/theme.css', $theme);
    file_put_contents($this->directory . '/site/assets/copied.css', $arbitraryCss);
    $this->resources();
    $css = file_get_contents($this->directory . '/resources/site.css');
    $javascript = file_get_contents($this->directory . '/resources/theme.js');
    assert(is_string($css));
    assert(is_string($javascript));

    $config = new ConfigLoader()->load($this->directory . '/site');
    new Publisher()->publish($this->directory, $config, $this->catalog());
    $readable = file_get_contents($this->directory . '/public/page/index.html');
    $readableCss = file_get_contents($this->directory . '/public/assets/site.css');
    $readableTheme = file_get_contents($this->directory . '/public/assets/theme.css');
    $readableArbitraryCss = file_get_contents($this->directory . '/public/assets/site/copied.css');
    assert(is_string($readable));
    assert(is_string($readableCss));

    $this->site(['build' => ['minify' => true]]);
    $config = new ConfigLoader()->load($this->directory . '/site');
    new Publisher()->publish($this->directory, $config, $this->catalog());
    $compact = file_get_contents($this->directory . '/public/page/index.html');
    $compactCss = file_get_contents($this->directory . '/public/assets/site.css');
    $compactTheme = file_get_contents($this->directory . '/public/assets/theme.css');
    assert(is_string($compact));
    assert(is_string($compactCss));
    assert(is_string($compactTheme));

    expect($readable)->toContain("\n    <head>\n", "Text  with <em>inline</em> spacing.")
        ->and($compact)->toContain('<html lang="en"> <head>', "Text  with <em>inline</em> spacing.", "const  value = &apos;&lt;tag&gt;&apos;;", '<script src="/assets/theme.js"></script>')
        ->and(mb_strlen($compact, '8bit'))->toBeLessThan(mb_strlen($readable, '8bit'))
        ->and($readableCss)->toBe($css)
        ->and($readableTheme)->toBe($theme)
        ->and($readableArbitraryCss)->toBe($arbitraryCss)
        ->and($compactCss)->not->toBe($css)
        ->and(mb_strlen($compactCss, '8bit'))->toBeLessThan(mb_strlen($css, '8bit'))
        ->and($compactTheme)->toBe('@layer overrides{:root{--custom: one;}}')
        ->and(file_get_contents($this->directory . '/public/assets/site/copied.css'))->toBe($arbitraryCss)
        ->and(file_get_contents($this->directory . '/public/assets/theme.js'))->toBe($javascript);
});

it('publishes every browser-facing URL beneath the configured deployment path without moving output files', function (): void {
    $this->site(['url' => 'https://example.test/snippet']);
    $this->item('about', ['title' => 'About', 'description' => 'About.', 'menu_order' => 1]);
    $articlePath = $this->article('post', [
        'title' => 'Post',
        'description' => 'Article.',
        'date' => '2026-01-01',
        'tags' => ['Café'],
        'cover' => true,
    ], '[about](/about/) [asset](notes.txt) [fragment](#part) [external](https://outside.test/)');
    file_put_contents($articlePath . '/notes.txt', 'notes');
    $this->image($articlePath . '/cover.webp');
    mkdir($this->directory . '/site/assets/fonts/snippet-logo', 0777, true);
    file_put_contents($this->directory . '/site/assets/fonts/snippet-logo/snippet-logo.woff2', 'font');
    file_put_contents($this->directory . '/site/theme.css', '@font-face { src: url("site/fonts/snippet-logo/snippet-logo.woff2"); }');
    $this->resources();

    expect(validatePublication($this->directory, 'build')[0])->toBe(0);

    $home = file_get_contents($this->directory . '/public/index.html');
    $article = file_get_contents($this->directory . '/public/articles/post/index.html');
    $llms = file_get_contents($this->directory . '/public/llms.txt');
    $theme = file_get_contents($this->directory . '/public/assets/theme.css');
    expect([$home, $article])->each->toBeString()
        ->and($home)->toContain('<link rel="canonical" href="https://example.test/snippet/">', '<script src="/snippet/assets/theme.js"></script>', '<link rel="stylesheet" href="/snippet/assets/site.css">', '<link rel="stylesheet" href="/snippet/assets/theme.css">', '<link rel="preload" href="/snippet/assets/site/fonts/snippet-logo/snippet-logo.woff2"', '<a class="site-brand" href="/snippet/"', '<a class="menu-link" href="/snippet/articles/">', '<a href="/snippet/tags/caf%C3%A9/"', '<img src="/snippet/articles/post/cover.webp"', '<a href="/snippet/articles/post/notes.txt">asset</a>')
        ->and($article)->toBeString()
        ->toContain('<link rel="canonical" href="https://example.test/snippet/articles/post/">', '<a href="/snippet/about/">about</a>', '<a href="notes.txt">asset</a>', '<a href="#part">fragment</a>', '<a href="https://outside.test/">external</a>', '<img src="/snippet/articles/post/cover.webp"')
        ->and($llms)->toBeString()
        ->toContain('https://example.test/snippet/articles/post/', 'https://example.test/snippet/about/')
        ->and($theme)->toBe('@font-face { src: url("site/fonts/snippet-logo/snippet-logo.woff2"); }')
        ->and(file_exists($this->directory . '/public/snippet'))->toBeFalse();
});
