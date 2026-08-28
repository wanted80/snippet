<?php

declare(strict_types=1);

use Snippet\Publishing\PublicationInputLoader;
use Snippet\Publishing\PublicationInventory;
use Snippet\Publishing\Publisher;
use Snippet\Site\ConfigLoader;

it('publishes every optional CSS and JavaScript combination with stable ordering and bytes', function (bool $stylesheet, bool $script): void {
    $this->item('page', ['title' => 'Page', 'description' => 'Description.'], 'Readable without JavaScript.');
    $this->resources();
    file_put_contents($this->directory . '/resources/site.css', 'legacy resource');
    file_put_contents($this->directory . '/site/theme.css', 'legacy site');
    $siteCss = "@layer overrides { :root { --custom: yes; } }\n";
    $siteJs = "window.siteCustomization = '<&>';\n";
    if ($stylesheet) {
        file_put_contents($this->directory . '/site/site.css', $siteCss);
    }
    if ($script) {
        file_put_contents($this->directory . '/site/site.js', $siteJs);
    }

    $config = new ConfigLoader()->load($this->directory . '/site');
    new Publisher()->publish($this->directory, $config, $this->catalog());
    $html = file_get_contents($this->directory . '/public/index.html');
    assert(is_string($html));

    expect($this->directory . '/public/assets/theme.css')->toBeFile()
        ->and($this->directory . '/public/assets/theme.js')->toBeFile()
        ->and(file_exists($this->directory . '/public/assets/site.css'))->toBe($stylesheet)
        ->and(file_exists($this->directory . '/public/assets/site.js'))->toBe($script)
        ->and($html)->toContain('<link rel="stylesheet" href="/assets/theme.css">', '<script src="/assets/theme.js"></script>');

    if ($stylesheet) {
        $themePosition = mb_strpos($html, '/assets/theme.css');
        $sitePosition = mb_strpos($html, '/assets/site.css');
        assert(is_int($themePosition));
        assert(is_int($sitePosition));
        expect(file_get_contents($this->directory . '/public/assets/site.css'))->toBe($siteCss)
            ->and($html)->toContain('<link rel="stylesheet" href="/assets/site.css">')
            ->and($themePosition)->toBeLessThan($sitePosition);
    } else {
        expect($html)->not->toContain('/assets/site.css');
    }

    if ($script) {
        $themePosition = mb_strpos($html, '/assets/theme.js');
        $sitePosition = mb_strpos($html, '/assets/site.js');
        assert(is_int($themePosition));
        assert(is_int($sitePosition));
        expect(file_get_contents($this->directory . '/public/assets/site.js'))->toBe($siteJs)
            ->and($html)->toContain('<script src="/assets/site.js" defer></script>')
            ->and($themePosition)->toBeLessThan($sitePosition);
    } else {
        expect($html)->not->toContain('/assets/site.js');
    }

    expect($html)->not->toContain('legacy resource', 'legacy site')
        ->and($this->directory . '/public/assets/theme.css')->toBeFile();
})->with([
    'required assets only' => [false, false],
    'optional CSS' => [true, false],
    'optional JavaScript' => [false, true],
    'optional CSS and JavaScript' => [true, true],
]);

it('keeps authored content, native navigation, and the system theme usable without JavaScript', function (): void {
    $this->item('page', ['title' => 'Page', 'description' => 'Description.'], 'Readable without JavaScript.');
    $this->resources();
    $config = new ConfigLoader()->load($this->directory . '/site');
    new Publisher()->publish($this->directory, $config, $this->catalog());
    $page = file_get_contents($this->directory . '/public/page/index.html');
    $css = file_get_contents($this->directory . '/public/assets/theme.css');
    assert(is_string($page));
    assert(is_string($css));

    expect($page)->toContain(
        '<p>Readable without JavaScript.</p>',
        'popovertarget="site-navigation"',
        '<nav class="site-navigation" id="site-navigation" popover aria-label="Primary">',
    )
        ->and($css)->toContain('@media (prefers-color-scheme: light)', ':root:not([data-theme])')
        ->toMatch('/\.theme-toggle\s*\{[^}]*display: none;/s')
        ->toMatch('/:root\[data-theme\] \.theme-toggle\s*\{[^}]*display: inline-grid;/s');
});

it('exposes only present v2 customization assets to internal reference validation', function (): void {
    $this->content();
    $this->resources();
    file_put_contents($this->directory . '/site/site.js', '/* custom */');
    $inputs = new PublicationInputLoader()->load($this->directory);
    $paths = new PublicationInventory($inputs->config, $inputs->catalog)->paths();

    expect($paths)->toContain('/assets/theme.css', '/assets/theme.js', '/assets/site.js')
        ->not->toContain('/assets/site.css');
});
