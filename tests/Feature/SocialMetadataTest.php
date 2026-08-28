<?php

declare(strict_types=1);

use Snippet\Publishing\Publisher;
use Snippet\Site\ConfigLoader;

it('renders escaped core social metadata for every generated page kind', function (): void {
    $covered = $this->article('covered', [
        'title' => 'Covered <日本語>',
        'description' => 'Covered & described.',
        'date' => '2026-01-02',
        'tags' => ['Café'],
        'cover' => true,
        'alt' => 'Cover <& "view".',
    ]);
    $this->image($covered . '/cover.webp');
    $this->article('plain', [
        'title' => 'Plain',
        'description' => 'Plain description.',
        'date' => '2026-01-01',
        'tags' => [],
    ]);
    $this->item('about', ['title' => 'About <us>', 'description' => 'About & description.']);
    $this->site([
        'title' => 'Site <日本語>',
        'sitename' => 'Wordmark',
        'description' => 'Site & description.',
    ]);
    $this->resources();

    $config = new ConfigLoader()->load($this->directory . '/site');
    new Publisher()->publish($this->directory, $config, $this->catalog());

    $home = file_get_contents($this->directory . '/public/index.html');
    $articles = file_get_contents($this->directory . '/public/articles/index.html');
    $tag = file_get_contents($this->directory . '/public/tags/café/index.html');
    $page = file_get_contents($this->directory . '/public/about/index.html');
    $coveredArticle = file_get_contents($this->directory . '/public/articles/covered/index.html');
    $plainArticle = file_get_contents($this->directory . '/public/articles/plain/index.html');
    expect([$home, $articles, $tag, $page, $coveredArticle, $plainArticle])->each->toBeString()
        ->and($home)->toContain('<meta property="og:type" content="website">', '<meta property="og:title" content="Site &lt;日本語&gt;">', '<meta property="og:description" content="Site &amp; description.">', '<meta property="og:url" content="https://example.test/">', '<meta property="og:site_name" content="Wordmark">', '<meta name="twitter:card" content="summary">', '<meta name="twitter:title" content="Site &lt;日本語&gt;">', '<meta name="twitter:description" content="Site &amp; description.">')
        ->and($articles)->toContain('<meta property="og:type" content="website">', '<meta property="og:title" content="Articles — Site &lt;日本語&gt;">', '<meta property="og:description" content="All articles on Site &lt;日本語&gt;.">', '<meta property="og:url" content="https://example.test/articles/">')
        ->and($tag)->toContain('<meta property="og:title" content="Tag: Café — Site &lt;日本語&gt;">', '<meta property="og:description" content="Articles tagged Café.">', '<meta property="og:url" content="https://example.test/tags/caf%C3%A9/">')
        ->and($page)->toContain('<meta property="og:type" content="website">', '<meta property="og:title" content="About &lt;us&gt;">', '<meta name="twitter:title" content="About &lt;us&gt;">')
        ->and($coveredArticle)->toContain('<meta property="og:type" content="article">', '<meta property="og:title" content="Covered &lt;日本語&gt;">', '<meta property="og:image" content="https://example.test/articles/covered/cover.webp">', '<meta property="og:image:type" content="image/webp">', '<meta property="og:image:width" content="1">', '<meta property="og:image:height" content="1">', '<meta property="og:image:alt" content="Cover &lt;&amp; &quot;view&quot;.">', '<meta name="twitter:card" content="summary_large_image">', '<meta name="twitter:image" content="https://example.test/articles/covered/cover.webp">', '<meta name="twitter:image:alt" content="Cover &lt;&amp; &quot;view&quot;.">')
        ->and($plainArticle)->toContain('<meta property="og:type" content="article">', '<meta property="og:title" content="Plain">', '<meta name="twitter:card" content="summary">')->not->toContain('og:image', 'twitter:image');
});

it('carries each validated cover format into social image metadata and omits empty alt fields', function (string $extension, string $image, string $mime): void {
    $path = $this->article('post', [
        'title' => 'Post',
        'description' => 'Description.',
        'date' => '2026-01-01',
        'tags' => [],
        'cover' => true,
    ]);
    $this->image($path . '/cover.' . $extension, $image);
    $this->resources();

    $config = new ConfigLoader()->load($this->directory . '/site');
    new Publisher()->publish($this->directory, $config, $this->catalog());
    $article = file_get_contents($this->directory . '/public/articles/post/index.html');

    expect($article)->toContain(
        '<meta property="og:image" content="https://example.test/articles/post/cover.' . $extension . '">',
        '<meta property="og:image:type" content="' . $mime . '">',
        '<meta name="twitter:image" content="https://example.test/articles/post/cover.' . $extension . '">',
    )->not->toContain('og:image:alt', 'twitter:image:alt');
})->with([
    'JPEG' => ['jpg', 'jpeg', 'image/jpeg'],
    'PNG' => ['png', 'png-wide', 'image/png'],
    'WebP' => ['webp', 'webp', 'image/webp'],
]);
