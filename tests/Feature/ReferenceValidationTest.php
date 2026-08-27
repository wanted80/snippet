<?php

declare(strict_types=1);

use Snippet\Content\Article;
use Snippet\Content\Catalog;
use Snippet\Markdown\Document;
use Snippet\Markdown\InlineBuilder;
use Snippet\Publishing\PublicationInputLoader;
use Snippet\Publishing\PublicationInventory;
use Snippet\Publishing\ReferenceValidator;
use Snippet\Site\Config;

mutates(ReferenceValidator::class);

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
    file_put_contents($path . '/article.md', $markdown . "\n[site asset](/assets/site/downloads/guide.pdf)\n[theme](/assets/site.css)\n");
    $this->resources();

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
        ->and($error)->toBe("Error: Internal link target '../missing/?x=1#part' in '{$path}/article.md' at line 9 does not exist in the generated site.\n");
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
        ->and($error)->toBe("Error: Internal link target 'HTTPS://EXAMPLE.TEST:443/missing/#part' in '{$path}/article.md' at line 1 does not exist in the generated site.\n");
});

it('reports the page source path for a missing page reference', function (): void {
    $path = $this->item('about', ['title' => 'About', 'description' => 'D'], '[missing](/missing/)');
    $this->resources();

    [$status, , $error] = validatePublication($this->directory);

    expect($status)->toBe(1)
        ->and($error)->toBe("Error: Internal link target '/missing/' in '{$path}/page.md' at line 1 does not exist in the generated site.\n");
});

it('exposes a sorted deterministic publication inventory', function (): void {
    $path = $this->article('post', [
        'title' => 'Post',
        'description' => 'D',
        'date' => '2026-01-01',
        'tags' => ['Café'],
    ]);
    file_put_contents($this->directory . '/site/theme.css', '/* theme */');
    file_put_contents($path . '/notes.txt', 'notes');
    $this->resources();

    $inputs = new PublicationInputLoader()->load($this->directory);
    $paths = new PublicationInventory($inputs->config, $inputs->catalog)->paths();
    $sorted = $paths;
    sort($sorted, SORT_STRING);

    expect($paths)->toBe($sorted)
        ->toContain('/articles/post/', '/articles/post/index.html', '/articles/post/notes.txt', '/llms.txt', '/tags/caf%C3%A9/');
});

it('fails explicitly if URL parsing invariants are bypassed', function (string $kind, string $message): void {
    $config = new Config(
        title: 'Test Site',
        sitename: 'Test Site',
        author: 'Test Author',
        description: 'D',
        url: $kind === 'origin' ? 'https://' : 'https://example.test',
        language: 'en',
        assets: [],
        hasTheme: false,
    );
    $target = $kind === 'target' ? 'https://' : 'https://example.test/';
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
        new PublicationInventory($config, $catalog),
    ))->toThrow(LogicException::class, $message);
})->with([
    'malformed parsed target' => [
        'target',
        'A parsed Markdown link target must be URL-parseable.',
    ],
    'malformed validated origin' => [
        'origin',
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
        ->and($error)->toBe("Error: Internal link target '{$target}' in '{$path}/article.md' at line 1 traverses above the site root.\n");
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
    $path = $this->article('post', [
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
        ->and($error)->toContain("Internal link target '{$target}' in '{$path}/article.md' at line 1");
})->with([
    'missing beneath base' => ['https://example.test/snippet/missing/', false],
    'deployment root' => ['https://example.test/snippet', true],
    'outside base' => ['https://example.test/outside-publication/missing/', true],
    'base prefix collision' => ['https://example.test/snippet-other/missing/', true],
    'base traversal' => ['https://example.test/snippet/%2e%2e/outside/', false],
]);
