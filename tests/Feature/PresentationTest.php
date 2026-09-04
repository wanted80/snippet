<?php

declare(strict_types=1);

use Snippet\Publishing\Publisher;
use Snippet\Site\ConfigLoader;
use Snippet\Support\ApplicationVersion;

it('ships print colors and wrapping that remain usable with long titles and manual themes', function (): void {
    $this->item('post', ['title' => str_repeat('W', 120), 'description' => 'Description']);
    $this->resources();
    $config = new ConfigLoader()->load($this->directory . '/site');
    new Publisher()->publish($this->directory, $config, $this->catalog());
    $html = file_get_contents($this->directory . '/public/post/index.html');
    $css = file_get_contents($this->publishedAsset('theme.css'));

    expect($html)->toContain('<meta name="color-scheme" content="dark light">', str_repeat('W', 120))
        ->and($css)->toMatch('/h4\s*\{[^}]*overflow-wrap: anywhere;/s')
        ->toMatch('/@media print\s*\{\s*:root,\s*:root\[data-theme\]\s*\{[^}]*color-scheme: only light;[^}]*--color-background: #fff;/s');
});

it('renders independent document, author, and multilingual wordmark identities', function (): void {
    $this->content();
    $this->site([
        'title' => 'Document & Identity',
        'sitename' => '日本語 & Snippet',
        'author' => '日本語 & Writer',
    ]);
    $this->resources();

    $config = new ConfigLoader()->load($this->directory . '/site');
    new Publisher()->publish($this->directory, $config, $this->catalog());

    $home = file_get_contents($this->directory . '/public/index.html');
    $css = file_get_contents($this->publishedAsset('theme.css'));
    $javascript = file_get_contents($this->publishedAsset('theme.js'));
    assert(is_string($home));
    assert(is_string($css));
    assert(is_string($javascript));

    expect($home)
        ->toContain('<title>Document &amp; Identity</title>')
        ->toContain('<meta name="author" content="日本語 &amp; Writer">')
        ->toContain('<meta name="generator" content="Snippet ' . ApplicationVersion::CURRENT . '">')
        ->toContain('<h1 class="visually-hidden">Document &amp; Identity</h1>')
        ->toContain('<a class="site-brand" href="/" aria-label="日本語 &amp; Snippet — Home"><span class="site-wordmark">日本語 &amp; Snippet</span></a>')
        ->toContain('<button class="menu-toggle icon-button" type="button" popovertarget="site-navigation" aria-controls="site-navigation" aria-label="Open navigation" title="Open navigation">')
        ->toContain('<button class="theme-toggle icon-button" type="button" data-theme-toggle aria-label="Toggle color theme" title="Toggle color theme">')
        ->toMatch('~class="menu-toggle icon-button"[\s\S]*class="site-brand"[\s\S]*class="theme-toggle icon-button"[\s\S]*class="site-navigation"~')
        ->not->toContain('aria-expanded=')
        ->and($css)->toContain('min-inline-size: 320px;', '--font-wordmark: var(--font-interface);')
        ->toContain('.site-header:has(.site-navigation:popover-open) .menu-toggle')
        ->toMatch('/\.site-header\s*\{[^}]*grid-template-columns: minmax\(2\.75rem, 1fr\) minmax\(0, auto\) minmax\(2\.75rem, 1fr\)/s')
        ->toMatch('/\.menu-toggle\s*\{[^}]*grid-column: 1;[^}]*justify-self: start/s')
        ->toMatch('/\.theme-toggle\s*\{[^}]*grid-column: 3;[^}]*justify-self: end/s')
        ->toMatch('/\.site-brand\s*\{[^}]*min-inline-size: 0;[^}]*max-inline-size: 100%;[^}]*overflow: clip visible;/s')
        ->toMatch('/\.site-wordmark\s*\{[^}]*display: block;[^}]*max-inline-size: 100%;[^}]*overflow: clip visible;[^}]*font-family: var\(--font-wordmark\);[^}]*font-size: clamp\(1rem, 3\.8vw, 1\.6rem\);[^}]*font-kerning: normal;[^}]*font-synthesis: none;[^}]*font-weight: 400;[^}]*line-height: 1\.15;[^}]*text-transform: uppercase;[^}]*white-space: nowrap;/s')
        ->toMatch('/\.article-figure\s*\{[^}]*inline-size: min\(100%, var\(--measure-prose\)\);[^}]*margin: 0 0 var\(--space-4\);/s')
        ->toMatch('/\.article-figure img\s*\{[^}]*inline-size: 100%;/s')
        ->toMatch('/\.prose\s*\{[^}]*hyphens: manual;/s')
        ->and($javascript)->toContain('syncScrollState();', "window.addEventListener('pageshow', syncScrollState)")
        ->not->toContain("menuButton.setAttribute('aria-expanded'");
});

it('ships and copies the configured wordmark font byte for byte', function (): void {
    $this->content();
    $fontDirectory = $this->directory . '/site/assets/fonts/snippet-logo';
    mkdir($fontDirectory, 0777, true);
    $source = file_get_contents(dirname(__DIR__, 2) . '/site/assets/fonts/snippet-logo/snippet-logo.woff2');
    assert(is_string($source));
    file_put_contents($fontDirectory . '/snippet-logo.woff2', $source);
    copy(dirname(__DIR__, 2) . '/site/site.css', $this->directory . '/site/site.css');
    $this->resources();

    $config = new ConfigLoader()->load($this->directory . '/site');
    new Publisher()->publish($this->directory, $config, $this->catalog());
    $published = file_get_contents($this->directory . '/public/assets/site/fonts/snippet-logo/snippet-logo.woff2');
    $theme = file_get_contents($this->publishedAsset('site.css'));
    assert(is_string($published));
    assert(is_string($theme));

    expect($published)->toBe($source)
        ->and($theme)->toContain(
            'font-family: "Snippet Logo";',
            'src: url("site/fonts/snippet-logo/snippet-logo.woff2") format("woff2");',
            'font-style: normal;',
            'font-weight: 400;',
            'font-display: swap;',
            '--font-wordmark: "Snippet Logo", var(--font-interface);',
        );
});

it('publishes a replacement favicon byte for byte', function (): void {
    $this->content();
    $this->resources();
    $favicon = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 16 16" width="16" height="16"><path fill="#123456" d="M0 0h16v16H0z"/></svg>
SVG;
    file_put_contents($this->directory . '/site/favicon.svg', $favicon);

    $config = new ConfigLoader()->load($this->directory . '/site');
    new Publisher()->publish($this->directory, $config, $this->catalog());
    $published = file_get_contents($this->directory . '/public/favicon.svg');
    $home = file_get_contents($this->directory . '/public/index.html');
    assert(is_string($published));
    assert(is_string($home));

    expect($published)->toBe($favicon)
        ->and($home)->toContain('<link rel="icon" href="/favicon.svg" type="image/svg+xml">');
});
