<?php

declare(strict_types=1);

use Snippet\Application;
use Snippet\Content\Article;
use Snippet\Content\CatalogLoader;
use Snippet\Content\Page;
use Snippet\Publishing\HtmlMinifier;
use Snippet\Publishing\ReferenceValidator;
use Snippet\Rendering\Template;
use Snippet\Rendering\TemplateLoader;
use Snippet\Site\Limits;

mutates(CatalogLoader::class, HtmlMinifier::class, ReferenceValidator::class, TemplateLoader::class);

it('accepts catalog values exactly at their resource ceilings', function (): void {
    $articlePath = $this->article('article', [
        'title' => 'A',
        'description' => 'D',
        'date' => '2026-01-01',
        'tags' => ['T'],
    ], 'A');
    file_put_contents($articlePath . '/a', 'a');
    $pagePath = $this->item('page', [
        'title' => 'P',
        'description' => 'D',
        'menu_order' => 1,
    ], 'P');
    file_put_contents($pagePath . '/a', 'a');

    $catalog = new CatalogLoader()->load($this->directory . '/content', new Limits(
        articles: 1,
        pages: 1,
        menuPages: 1,
        tagsPerArticle: 1,
        tagCharacters: 1,
        titleCharacters: 1,
        descriptionCharacters: 1,
        markdownBytes: 1,
        catalogMarkdownBytes: 2,
        documentNodes: 2,
        catalogNodes: 4,
        assetsPerItem: 1,
        catalogAssets: 2,
        assetDepth: 1,
        assetBytes: 1,
        catalogAssetBytes: 2,
    ));

    expect($catalog->count())->toBe(2)
        ->and($catalog->articles)->toHaveCount(1)
        ->and($catalog->articles[0]->assets)->toHaveCount(1)
        ->and($catalog->pages)->toHaveCount(1)
        ->and($catalog->pages[0]->assets)->toHaveCount(1);
});

it('orders adjacent titles and dates independently of opposing slugs', function (): void {
    $this->article('a', ['title' => 'Older', 'description' => 'D', 'date' => '2026-01-01', 'tags' => []]);
    $this->article('z', ['title' => 'Newer', 'description' => 'D', 'date' => '2026-01-02', 'tags' => []]);
    $this->item('a', ['title' => 'B', 'description' => 'D']);
    $this->item('z', ['title' => 'A', 'description' => 'D']);

    $catalog = $this->catalog();

    expect(array_map(static fn(Article $article): string => $article->slug, $catalog->articles))->toBe(['z', 'a'])
        ->and(array_map(static fn(Page $page): string => $page->slug, $catalog->pages))->toBe(['z', 'a']);
});

it('accepts template bytes exactly at the individual and aggregate ceilings', function (): void {
    $this->resources();
    $paths = glob($this->directory . '/resources/templates/*.html');
    assert(is_array($paths));
    $sizes = array_map(static function (string $path): int {
        $size = filesize($path);
        assert(is_int($size));

        return $size;
    }, $paths);
    if ($sizes === []) {
        throw new LogicException("Expected the template fixture to contain HTML files.");
    }

    $templates = new TemplateLoader()->load($this->directory . '/resources/templates', new Limits(
        templateBytes: max($sizes),
        allTemplateBytes: array_sum($sizes),
    ));

    expect($templates->render(Template::TagItem, ['url' => '/tags/php/', 'label' => 'PHP']))->toContain('PHP');
});

it('distinguishes same-origin custom ports from external port variants', function (string $target, int $expectedStatus): void {
    $this->site(['url' => 'https://example.test:8443']);
    $this->article('post', [
        'title' => 'Post',
        'description' => 'D',
        'date' => '2026-01-01',
        'tags' => [],
    ], "[target]({$target})");
    $this->resources();
    $stdout = new SplFileObject('php://memory', 'w+');
    $stderr = new SplFileObject('php://memory', 'w+');

    $status = new Application($this->directory)->run(['bin/snippet', 'validate'], $stdout, $stderr);

    expect($status)->toBe($expectedStatus);
})->with([
    'same explicit port is internal' => ['https://example.test:8443/missing/', 1],
    'different explicit port is external' => ['https://example.test:8444/missing/', 0],
    'default HTTPS port is external to a custom-port site' => ['https://example.test/missing/', 0],
]);

it('preserves leading whitespace before the first HTML tag', function (): void {
    $html = " \n<p>Text</p>";

    expect(new HtmlMinifier()->minify($html))->toBe($html);
});
