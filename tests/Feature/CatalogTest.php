<?php

declare(strict_types=1);

use Snippet\Content\Article;
use Snippet\Content\Asset;
use Snippet\Content\CatalogLoader;
use Snippet\Content\ContentType;
use Snippet\Content\Page;
use Snippet\Content\Tag;
use Snippet\Content\TagUsage;

mutates(CatalogLoader::class);

it('loads, orders, resolves and deterministically represents a complete catalog', function (): void {
    $this->content();
    $this->article('z-last', ['title' => 'Z', 'description' => 'D', 'date' => '2026-01-01', 'tags' => [
        'PHP',
        'Web',
    ]], '# Z');
    $item = $this->article('a-new', ['title' => 'A', 'description' => 'D', 'date' => '2026-02-01', 'tags' => ['PHP']], 'New');
    mkdir($item . '/media/deep', 0777, true);
    file_put_contents($item . '/z.txt', 'z');
    file_put_contents($item . '/media/b.txt', 'b');
    file_put_contents($item . '/media/deep/a.txt', 'a');
    $this->item('page-z', ['title' => 'Same', 'description' => 'D']);
    $this->item('page-a', ['title' => 'Same', 'description' => 'D']);
    $this->item('about', ['title' => 'About', 'description' => 'D']);

    $first = $this->catalog();
    $second = $this->catalog();

    expect($first->count())->toBe(5)
        ->and(array_map(static fn(Article $article): string => $article->slug, $first->articles))->toBe(['a-new', 'z-last'])
        ->and(array_map(static fn(Page $page): string => $page->slug, $first->pages))->toBe(['about', 'page-a', 'page-z'])
        ->and($first->articles[0]->url())->toBe('/articles/a-new/')
        ->and($first->articles[0]->type())->toBe(ContentType::Article)
        ->and($first->pages[0]->url())->toBe('/about/')
        ->and($first->pages[0]->type())->toBe(ContentType::Page)
        ->and(array_map(static fn(Asset $asset): string => $asset->path, $first->articles[0]->assets))->toBe(['media/b.txt', 'media/deep/a.txt', 'z.txt'])
        ->and(array_map(static fn(Tag $tag): string => $tag->label, $first->tags()))->toBe(['PHP', 'Web'])
        ->and(array_map(static fn(TagUsage $usage): string => $usage->tag->label . ':' . $usage->articles, $first->tagUsages()))->toBe(['PHP:2', 'Web:1'])
        ->and(array_map(static fn(Article $article): string => $article->slug, $first->articlesForTag(new Tag('PHP', 'php'))))->toBe(['a-new', 'z-last'])
        ->and($first->articlesForTag(new Tag('Missing', 'missing')))->toBeEmpty()
        ->and(serialize($first))->toBe(serialize($second));
});

it('orders tags by occurrence count, then by natural label order', function (): void {
    $this->article('first', ['title' => 'First', 'description' => 'D', 'date' => '2026-01-01', 'tags' => [
        'Tag 10',
        'Tag 2',
        'Popular',
    ]]);
    $this->article('second', ['title' => 'Second', 'description' => 'D', 'date' => '2026-01-02', 'tags' => [
        'Tag 10',
        'Popular',
    ]]);
    $this->article('third', ['title' => 'Third', 'description' => 'D', 'date' => '2026-01-03', 'tags' => ['Tag 2', 'Popular']]);

    expect(array_map(static fn(TagUsage $usage): string => $usage->tag->label . ':' . $usage->articles, $this->catalog()->tagUsages()))
        ->toBe(['Popular:3', 'Tag 2:2', 'Tag 10:2']);
});

it('accepts an empty content directory', function (): void {
    $this->content();
    expect($this->catalog()->count())->toBe(0);
});

it('keeps multilingual titles behind author-chosen ASCII content slugs', function (): void {
    $this->item('cafe', ['title' => 'Café', 'description' => 'D']);
    $this->article('nihongo', ['title' => '日本語', 'description' => 'D', 'date' => '2026-01-01', 'tags' => []]);

    $catalog = $this->catalog();
    expect($catalog->pages[0]->title)->toBe('Café')
        ->and($catalog->pages[0]->url())->toBe('/cafe/')
        ->and($catalog->articles[0]->title)->toBe('日本語')
        ->and($catalog->articles[0]->url())->toBe('/articles/nihongo/');
});

it('discovers pages and date-organized articles from separate trees', function (): void {
    $page = $this->item('about', ['title' => 'About', 'description' => 'D']);
    $article = $this->article('post', ['title' => 'Post', 'description' => 'D', 'date' => '2026-08-09', 'tags' => []]);

    expect($page)->toEndWith('/content/pages/about')
        ->and($page . '/page.md')->toBeFile()
        ->and($article)->toEndWith('/content/articles/2026/08/09/post')
        ->and($article . '/article.md')->toBeFile()
        ->and($this->catalog()->count())->toBe(2);
});
