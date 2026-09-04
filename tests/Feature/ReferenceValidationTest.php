<?php

declare(strict_types=1);

use Snippet\Content\Article;
use Snippet\Content\Catalog;
use Snippet\Markdown\Document;
use Snippet\Markdown\InlineBuilder;
use Snippet\Publishing\PublicationInputLoader;
use Snippet\Publishing\PublicationInventory;
use Snippet\Publishing\ReferenceValidator;
use Snippet\Rendering\AssetPaths;
use Snippet\Site\Config;

mutates(ReferenceValidator::class);

it('encodes literal asset filenames without treating percent sequences as URLs', function (string $filename, string $encoded): void {
    $path = $this->item('post', ['title' => 'Post', 'description' => 'D'], "[content]({$encoded}) [site](/assets/site/{$encoded})");
    $this->resources();
    mkdir($this->directory . '/site/assets');
    file_put_contents($path . '/' . $filename, 'content asset');
    file_put_contents($this->directory . '/site/assets/' . $filename, 'site asset');

    expect(validatePublication($this->directory)[0])->toBe(0);
})->with([
    'literal space' => ['a b.txt', 'a%20b.txt'],
    'literal encoded space' => ['a%20b.txt', 'a%2520b.txt'],
    'literal encoded slash' => ['a%2Fb.txt', 'a%252Fb.txt'],
    'literal fragment character' => ['a#b.txt', 'a%23b.txt'],
    'Unicode and percent' => ['café%20.txt', 'caf%C3%A9%2520.txt'],
]);

it('rejects links that confuse a percent filename with its decoded spelling', function (): void {
    $path = $this->item('post', ['title' => 'Post', 'description' => 'D'], '[missing](a%20b.txt)');
    $this->resources();
    file_put_contents($path . '/a%20b.txt', 'literal percent');

    [$status, , $error] = validatePublication($this->directory);

    expect($status)->toBe(1)->and($error)->toContain("Internal link target 'a%20b.txt'", 'does not exist');
});

it('accepts every generated route and copied-asset reference form', function (): void {
    $path = $this->article('post', [
        'title' => 'Post',
        'description' => 'D',
        'date' => '2026-01-01',
        'tags' => ['Café'],
    ], <<<'MARKDOWN'
[home](/)
[article index](/articles/)
[explicit page index](/pages/index.html?view=all#top)
[item asset](files/data.txt?download=1)
[normalized asset](./files/../files/data.txt)
[parent page](../../about/)
[same origin](https://example.test/about/#details)
[same origin root](https://example.test)
[Unicode tag route](/tags/caf%C3%A9/)
[raw Unicode tag route](/tags/café/)
[fragment](#local)
[external](https://outside.test/missing)
MARKDOWN);
    mkdir($path . '/files');
    file_put_contents($path . '/files/data.txt', 'data');
    $this->item('about', ['title' => 'About', 'description' => 'D']);
    mkdir($this->directory . '/site/assets/downloads', 0777, true);
    file_put_contents($this->directory . '/site/assets/downloads/guide.pdf', 'PDF');
    $markdown = file_get_contents($path . '/article.md');
    assert(is_string($markdown));
    $this->resources();
    $theme = file_get_contents($this->directory . '/resources/theme.css');
    assert(is_string($theme));
    $themePath = '/assets/theme.' . hash('xxh3', $theme) . '.css';
    file_put_contents($path . '/article.md', $markdown . "\n[site asset](/assets/site/downloads/guide.pdf)\n[theme]({$themePath})\n");

    expect(validatePublication($this->directory)[0])->toBe(0);
});

it('reports missing internal targets with the Markdown source path and exact line', function (): void {
    $path = $this->article('post', [
        'title' => 'Post',
        'description' => 'D',
        'date' => '2026-01-01',
        'tags' => [],
    ], "\nIntro.\n[home](/)\n\n\x60\x60\x60txt\n[not a link](/ignored/)\n\x60\x60\x60\n\n[missing](../missing/?x=1#part)\nFollowing text.");
    $this->resources();

    [$status, , $error] = validatePublication($this->directory);

    expect($status)->toBe(1)
        ->and($error)->toBe("Validation failed: Internal link target '../missing/?x=1#part' in 'content/articles/2026/01/01/post/article.md' at line 9 does not exist in the generated site.\n");
});

it('checks missing same-origin absolute links instead of treating them as external', function (): void {
    $path = $this->article('post', [
        'title' => 'Post',
        'description' => 'D',
        'date' => '2026-01-01',
        'tags' => [],
    ], '[missing](HTTPS://EXAMPLE.TEST:443/missing/#part)');
    $this->resources();

    [$status, , $error] = validatePublication($this->directory);

    expect($status)->toBe(1)
        ->and($error)->toBe("Validation failed: Internal link target 'HTTPS://EXAMPLE.TEST:443/missing/#part' in 'content/articles/2026/01/01/post/article.md' at line 1 does not exist in the generated site.\n");
});

it('reports the page source path for a missing page reference', function (): void {
    $path = $this->item('about', ['title' => 'About', 'description' => 'D'], '[missing](/missing/)');
    $this->resources();

    [$status, , $error] = validatePublication($this->directory);

    expect($status)->toBe(1)
        ->and($error)->toBe("Validation failed: Internal link target '/missing/' in 'content/pages/about/page.md' at line 1 does not exist in the generated site.\n");
});

it('exposes a sorted deterministic publication inventory', function (): void {
    $path = $this->article('post', [
        'title' => 'Post',
        'description' => 'D',
        'date' => '2026-01-01',
        'tags' => ['Café'],
    ]);
    file_put_contents($this->directory . '/site/site.css', '/* site */');
    file_put_contents($this->directory . '/site/site.js', '/* site */');
    file_put_contents($path . '/notes.txt', 'notes');
    $this->resources();

    $inputs = new PublicationInputLoader()->load($this->directory);
    $paths = new PublicationInventory($inputs->config, $inputs->catalog, $inputs->assets->paths)->paths();
    $sorted = $paths;
    sort($sorted, SORT_STRING);

    expect($paths)->toBe($sorted)
        ->toContain('/404.html', '/articles/post/', '/articles/post/index.html', '/articles/post/notes.txt', $inputs->assets->themeStylesheet->publishedPath, $inputs->assets->themeScript->publishedPath, $inputs->assets->siteStylesheet?->publishedPath, $inputs->assets->siteScript?->publishedPath, '/favicon.svg', '/llms.txt', '/tags/caf%C3%A9/');
});

it('fails explicitly if URL parsing invariants are bypassed', function (string $configUrl, string $target, string $message): void {
    $config = new Config(
        title: 'Test Site',
        sitename: 'Test Site',
        author: 'Test Author',
        description: 'D',
        url: $configUrl,
        language: 'en',
        assets: [],
        hasSiteStylesheet: false,
    );
    $inlines = new InlineBuilder();
    $inlines->link(0, mb_strlen($target, '8bit'));

    $article = new Article(
        slug: 'post',
        title: 'Post',
        description: 'D',
        date: '2026-01-01',
        tags: [],
        document: new Document($target, [], [], $inlines->finish()),
        assets: [],
    );
    $catalog = new Catalog([$article], []);

    expect(fn() => new ReferenceValidator()->validate(
        $this->directory,
        $config,
        $catalog,
        new PublicationInventory($config, $catalog, new AssetPaths('/assets/theme.a.css', '/assets/theme.b.js', null, null)),
    ))->toThrow(LogicException::class, $message);
})->with([
    'malformed parsed target' => [
        'https://example.test',
        'https://example.test/bad%escape',
        'A parsed Markdown link target must be URL-parseable.',
    ],
    'hostless parsed target' => [
        'https://example.test',
        'https://',
        'A parsed Markdown link target must be URL-parseable.',
    ],
    'unparseable validated origin' => [
        'https://example.test/bad%escape',
        'https://example.test/',
        'A validated site origin must be URL-parseable.',
    ],
    'schemeless validated origin' => [
        'example.test',
        'https://example.test/',
        'A validated site origin must be URL-parseable.',
    ],
    'hostless validated origin' => [
        'https:',
        'https://example.test/',
        'A validated site origin must be URL-parseable.',
    ],
    'empty-host validated origin' => [
        'https://',
        'https://example.test/',
        'A validated site origin must be URL-parseable.',
    ],
]);

it('rejects internal traversal above the site root', function (string $target): void {
    $path = $this->article('post', [
        'title' => 'Post',
        'description' => 'D',
        'date' => '2026-01-01',
        'tags' => [],
    ], "[escape]({$target})");
    $this->resources();

    [$status, , $error] = validatePublication($this->directory);

    expect($status)->toBe(1)
        ->and($error)->toBe("Validation failed: Internal link target '{$target}' in 'content/articles/2026/01/01/post/article.md' at line 1 traverses above the site root.\n");
})->with([
    'root relative' => '/../../outside',
    'item relative' => '../../../outside',
    'encoded dot segments' => '/%2e%2e/outside',
]);

it('preserves the current publication when a build introduces a missing reference', function (): void {
    $path = $this->item('page', ['title' => 'Page', 'description' => 'D'], 'Valid.');
    $this->resources();
    expect(validatePublication($this->directory, 'build')[0])->toBe(0);
    $published = file_get_contents($this->directory . '/public/page/index.html');
    file_put_contents($path . '/page.md', '[missing](/missing/)');

    expect(validatePublication($this->directory, 'build')[0])->toBe(1)
        ->and(file_get_contents($this->directory . '/public/page/index.html'))->toBe($published);
});

it('validates portable and same-site links relative to a configured deployment path', function (): void {
    $this->site(['url' => 'https://example.test/snippet']);
    $path = $this->article('post', [
        'title' => 'Post',
        'description' => 'D',
        'date' => '2026-01-01',
        'tags' => [],
    ], <<<'MARKDOWN'
[root portable](/about/?view=all#top)
[item relative](files/data.txt)
[same-site base](https://example.test/snippet/about/)
[outside publication](https://example.test/outside/)
[fragment](#part)
MARKDOWN);
    mkdir($path . '/files');
    file_put_contents($path . '/files/data.txt', 'data');
    $this->item('about', ['title' => 'About', 'description' => 'D']);
    $this->resources();

    expect(validatePublication($this->directory)[0])->toBe(0);
});

it('checks same-origin targets beneath the deployment path and ignores targets outside it', function (string $target, bool $valid): void {
    $this->site(['url' => 'https://example.test/snippet']);
    $this->article('post', [
        'title' => 'Post',
        'description' => 'D',
        'date' => '2026-01-01',
        'tags' => [],
    ], "[target]({$target})");
    $this->resources();

    [$status, , $error] = validatePublication($this->directory);

    if ($valid) {
        expect($status)->toBe(0)->and($error)->toBeEmpty();
        return;
    }

    expect($status)->toBe(1)
        ->and($error)->toContain("Internal link target '{$target}' in 'content/articles/2026/01/01/post/article.md' at line 1");
})->with([
    'missing beneath base' => ['https://example.test/snippet/missing/', false],
    'deployment root' => ['https://example.test/snippet', true],
    'outside base' => ['https://example.test/outside-publication/missing/', true],
    'base prefix collision' => ['https://example.test/snippet-other/missing/', true],
    'base traversal' => ['https://example.test/snippet/%2e%2e/outside/', false],
]);
