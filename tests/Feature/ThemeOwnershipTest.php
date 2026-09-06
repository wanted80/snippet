<?php

declare(strict_types=1);

use Snippet\Publishing\PublicationInputLoader;
use Snippet\Publishing\Publisher;
use Snippet\Scaffolding\WorkspaceInitializer;

it('initializes only author-owned files and builds with the installed theme', function (): void {
    $workspace = $this->directory . '/publication';
    mkdir($workspace);
    $initializer = new WorkspaceInitializer(dirname(__DIR__, 2), $workspace);
    $result = $initializer->initialize();

    expect($workspace . '/resources')->not->toBeDirectory()
        ->and($workspace . '/site/config.php')->toBeFile()
        ->and($workspace . '/site/site.css')->toBeFile()
        ->and($workspace . '/content/pages')->toBeDirectory()
        ->and($result['created'])->not->toContain('resources/theme.css', 'resources/theme.js');

    $customCss = '@layer overrides { :root { --color-accent: rebeccapurple; } }';
    file_put_contents($workspace . '/site/site.css', $customCss);
    $repeated = $initializer->initialize();
    expect($repeated['created'])->toBeEmpty();
    [$status, , $error] = validatePublication($workspace, 'build');

    expect($status)->toBe(0)->and($error)->toBeEmpty()
        ->and(file_get_contents($workspace . '/site/site.css'))->toBe($customCss)
        ->and(file_get_contents($workspace . '/public/index.html'))->toContain('class="site-header"')
        ->and($workspace . '/resources')->not->toBeDirectory();
});

it('uses an upgraded engine theme while preserving author CSS and JavaScript', function (): void {
    $this->resources();
    $workspace = $this->directory . '/publication';
    mkdir($workspace);
    $initialized = new WorkspaceInitializer($this->directory, $workspace)->initialize();
    expect($initialized['created'])->not->toBeEmpty();
    $customCss = '@layer overrides { :root { --measure-prose: 42rem; } }';
    file_put_contents($workspace . '/site/site.css', $customCss);
    $customJs = 'document.querySelector(".site-main")?.classList.add("custom");';
    file_put_contents($workspace . '/site/site.js', $customJs);
    $publisher = new Publisher(engineRoot: $this->directory);
    $inputs = new PublicationInputLoader(publisher: $publisher);
    $first = $inputs->load($workspace);
    $publisher->publish($workspace, $first->config, $first->catalog);

    file_put_contents($this->directory . '/resources/theme.css', "\n/* upgraded theme */\n", FILE_APPEND);
    file_put_contents($this->directory . '/resources/theme.js', "\n/* upgraded behavior */\n", FILE_APPEND);
    $layout = $this->directory . '/resources/templates/layout.html';
    $html = file_get_contents($layout);
    assert(is_string($html));
    file_put_contents($layout, str_replace('<body>', '<body class="upgraded">', $html));
    $repeated = new WorkspaceInitializer($this->directory, $workspace)->initialize();
    expect($repeated['created'])->toBeEmpty();
    $second = $inputs->load($workspace);
    $publisher->publish($workspace, $second->config, $second->catalog);

    expect($second->assets->paths->themeStylesheet)->not->toBe($first->assets->paths->themeStylesheet)
        ->and($second->assets->paths->themeScript)->not->toBe($first->assets->paths->themeScript)
        ->and($second->assets->siteStylesheet?->contents)->toBe($customCss)
        ->and($second->assets->siteScript?->contents)->toBe($customJs)
        ->and($second->assets->paths->siteScript)->toBe($first->assets->paths->siteScript)
        ->and(file_get_contents($workspace . '/site/site.js'))->toBe($customJs)
        ->and(file_get_contents($workspace . '/public/index.html'))->toContain('<body class="upgraded">')
        ->and(file_get_contents($workspace . '/site/site.css'))->toBe($customCss)
        ->and($workspace . '/resources')->not->toBeDirectory();
});

it('uses only the installed templates and theme assets even when workspace names overlap', function (): void {
    $this->content();
    mkdir($this->directory . '/resources/templates', 0777, true);
    file_put_contents($this->directory . '/resources/templates/layout.html', 'Unsupported local layout.');
    file_put_contents($this->directory . '/resources/theme.css', 'Unsupported local stylesheet.');
    file_put_contents($this->directory . '/resources/theme.js', 'Unsupported local script.');

    [$status, , $error] = validatePublication($this->directory, 'build');

    expect($status)->toBe(0)->and($error)->toBeEmpty()
        ->and(file_get_contents($this->directory . '/public/index.html'))->toContain('class="site-header"')
        ->and(file_get_contents($this->publishedAsset('theme.css')))->toBe(file_get_contents(dirname(__DIR__, 2) . '/resources/theme.css'))
        ->and(file_get_contents($this->publishedAsset('theme.js')))->toBe(file_get_contents(dirname(__DIR__, 2) . '/resources/theme.js'));
});
