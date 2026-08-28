<?php

declare(strict_types=1);

use Snippet\Exception\ContentException;
use Snippet\Rendering\Template;
use Snippet\Rendering\TemplateLoader;
use Snippet\Support\ApplicationVersion;

mutates(TemplateLoader::class);

it('defines the consolidated twelve-template interface', function (): void {
    $contracts = [];
    foreach (Template::cases() as $template) {
        $contracts[$template->value] = $template->placeholders();
    }

    expect($contracts)->toBe([
        'layout.html' => ['language', 'description', 'author', 'version', 'title', 'canonical', 'social_metadata', 'base_path', 'preloads', 'site_stylesheet', 'site_script', 'sitename', 'navigation', 'body'],
        'home.html' => ['site_title', 'featured_article', 'archive_section', 'tag_section', 'empty_state', 'home_grid_class'],
        'featured-article.html' => ['url', 'title', 'date', 'tags', 'figure', 'document'],
        'article-figure.html' => ['url', 'alt', 'width', 'height'],
        'home-collection.html' => ['section_class', 'heading_id', 'eyebrow', 'title', 'list_class', 'items', 'index_link'],
        'archive-item.html' => ['url', 'title', 'date'],
        'tag-item.html' => ['url', 'label'],
        'tag-usage.html' => ['url', 'label', 'count', 'accessible_label'],
        'content-page.html' => ['content_class', 'title', 'metadata', 'figure', 'document'],
        'collection-page.html' => ['eyebrow', 'title', 'introduction', 'collection_label', 'list_class', 'items', 'empty_state'],
        'content-summary.html' => ['url', 'title', 'date', 'description', 'tags'],
        'tag-list.html' => ['items'],
    ]);

    $this->resources();
    $files = scandir($this->directory . '/resources/templates');
    assert(is_array($files));
    $files = array_values(array_diff($files, ['.', '..']));
    sort($files, SORT_STRING);
    $expected = array_keys($contracts);
    sort($expected, SORT_STRING);

    expect($files)->toBe($expected);
});

it('loads every HTML template and substitutes trusted rendered values', function (): void {
    $this->resources();
    $templates = new TemplateLoader()->load($this->directory . '/resources/templates');

    expect($templates->render(Template::TagItem, [
        'url' => '/tags/php/',
        'label' => 'PHP &amp; Web',
    ]))->toBe("<li><a href=\"/tags/php/\"><span class=\"tag-label\">PHP &amp; Web</span></a></li>\n")
        ->and($templates->render(Template::ArticleFigure, [
            'url' => '/articles/post/cover.webp',
            'alt' => 'Alt',
            'width' => '1',
            'height' => '1',
        ]))->toContain('<figure class="article-figure">')
        ->not->toContain('{{alt}}', '{{width}}', '{{height}}');
});

it('keeps the default layout self-contained and loads only local stylesheets', function (): void {
    $this->resources();
    $templates = new TemplateLoader()->load($this->directory . '/resources/templates');

    $layout = $templates->render(Template::Layout, [
        'language' => 'en',
        'description' => 'Description',
        'author' => 'Writer &amp; Editor',
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
        'body' => '',
    ]);

    expect($layout)->toContain(
        '<meta name="author" content="Writer &amp; Editor">',
        '<meta name="generator" content="Snippet ' . ApplicationVersion::CURRENT . '">',
        '<link rel="icon" href="/favicon.svg" type="image/svg+xml">',
        '<link rel="stylesheet" href="/assets/theme.css">',
        '<p class="site-footer-row">',
        '<span>Generated and published with</span>',
        '<span class="site-footer-heart" aria-hidden="true">♥</span>',
        '<a href="https://github.com/wanted80/snippet"><svg class="site-footer-github" viewBox="0 0 24 24" aria-hidden="true" focusable="false">',
        '<span>Snippet</span></a>',
        "style-src 'self'",
        "font-src 'self'",
        "img-src 'self'",
    )->not->toContain('{{preloads}}', '{{site_stylesheet}}', '{{site_script}}', '{{social_metadata}}', 'fonts.bunny.net', 'frame-ancestors');
});

it('rejects a missing or linked required template', function (string $kind): void {
    $this->resources();
    $path = $this->directory . '/resources/templates/collection-page.html';
    unlink($path);
    if ($kind === 'link') {
        symlink($this->directory . '/resources/templates/home.html', $path);
    }

    (void) new TemplateLoader()->load($this->directory . '/resources/templates');
})->throws(ContentException::class, 'collection-page.html')
    ->with(['missing', 'link']);

it('rejects invalid UTF-8 and template placeholders', function (string $kind): void {
    $this->resources();
    $path = $this->directory . '/resources/templates/home.html';
    $template = file_get_contents($path);
    assert(is_string($template));

    $invalid = match ($kind) {
        'encoding' => "\xFF",
        'missing' => str_replace('{{featured_article}}', '', $template),
        'unknown' => $template . '{{surprise}}',
        'malformed' => str_replace('{{featured_article}}', '{{ featured_article }}', $template),
        default => throw new LogicException("Unknown template test kind: {$kind}"),
    };
    file_put_contents($path, $invalid);

    $message = $kind === 'encoding'
        ? "HTML template '{$path}' must be readable UTF-8 text."
        : "HTML template '{$path}' must contain exactly these placeholders: {{site_title}}, {{featured_article}}, {{archive_section}}, {{tag_section}}, {{empty_state}}, {{home_grid_class}}.";

    expect(function (): void {
        (void) new TemplateLoader()->load($this->directory . '/resources/templates');
        throw new LogicException('Expected template loading to fail.');
    })->toThrow(ContentException::class, $message);
})->with([
    'encoding' => 'encoding',
    'missing placeholder' => 'missing',
    'unknown placeholder' => 'unknown',
    'malformed placeholder' => 'malformed',
]);
