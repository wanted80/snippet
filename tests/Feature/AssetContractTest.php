<?php

declare(strict_types=1);

use Snippet\Exception\ContentException;
use Snippet\Publishing\BuildReport;
use Snippet\Publishing\PublicationAsset;
use Snippet\Publishing\PublicationAssets;
use Snippet\Publishing\PublicationInputLoader;
use Snippet\Publishing\PublicationInputs;
use Snippet\Publishing\PublicationInventory;
use Snippet\Publishing\PublicationResources;
use Snippet\Publishing\Publisher;
use Snippet\Site\Config;
use Snippet\Site\ConfigLoader;
use Snippet\Site\Limits;
use Snippet\Tests\PublisherFaults;

mutates(PublicationAssets::class, PublicationInputs::class, PublicationInventory::class, Publisher::class);

it('always compacts entry CSS while preserving JavaScript and generated HTML', function (): void {
    $this->content();
    $this->resources();
    $stylesheet = "a { color: red; }\n";
    $javascript = "// ordinary comment\nconst  value = 'unchanged';\n";
    file_put_contents($this->directory . '/resources/theme.css', $stylesheet);
    file_put_contents($this->directory . '/resources/theme.js', $javascript);
    file_put_contents($this->directory . '/site/site.css', $stylesheet);
    file_put_contents($this->directory . '/site/site.js', $javascript);
    $config = new ConfigLoader()->load($this->directory . '/site');
    $publisher = new Publisher(engineRoot: $this->directory);
    $resources = $publisher->validatedResources($this->directory, $config);

    expect($resources->assets->themeStylesheet->contents)->toBe('a{color: red;}')
        ->and($resources->assets->siteStylesheet?->contents)->toBe('a{color: red;}')
        ->and($resources->assets->themeScript->contents)->toBe($javascript)
        ->and($resources->assets->siteScript?->contents)->toBe($javascript);

    $publisher->publish($this->directory, $config, $this->catalog());
    expect(file_get_contents($this->directory . '/public/index.html'))->toContain("\n    <head>\n");
});

it('enforces traversal depth even when author asset directories contain no files', function (string $collection): void {
    $root = $collection === 'content'
        ? $this->item('post', ['title' => 'Post', 'description' => 'Description.'])
        : $this->directory . '/site/assets';
    $this->content();
    mkdir($root . '/one/two', 0777, true);

    expect(fn(): PublicationInputs => new PublicationInputLoader(limits: new Limits(assetDepth: 1))->load($this->directory))
        ->toThrow(ContentException::class, 'directory depth 1');
})->with(['content', 'site']);

it('bounds site asset file counts while loading configuration', function (): void {
    mkdir($this->directory . '/site/assets');
    file_put_contents($this->directory . '/site/assets/one', 'asset');
    file_put_contents($this->directory . '/site/assets/two', 'asset');

    expect(fn(): Config => new ConfigLoader()->load($this->directory . '/site', new Limits(catalogAssets: 1)))
        ->toThrow(ContentException::class, '1-file limit');
});

it('requires a filename extension at the fingerprinted asset boundary', function (): void {
    expect(fn(): PublicationAsset => new PublicationAsset('/assets/theme', 'contents'))
        ->toThrow(LogicException::class, "Publication asset path '/assets/theme' must have an extension.");
});

it('fingerprints entry assets from their exact published bytes with XXH3', function (): void {
    $this->content();
    $this->resources();
    $themeCss = "@layer theme {\n    :root { color: red; }\n}\n";
    $themeJs = "/* ordinary */document.documentElement.dataset.ready = 'yes';\n";
    $siteCss = "@layer overrides {\n    :root { color: blue; }\n}\n";
    $siteJs = "/*! license */window.siteReady   = true;\n";
    file_put_contents($this->directory . '/resources/theme.css', $themeCss);
    file_put_contents($this->directory . '/resources/theme.js', $themeJs);
    file_put_contents($this->directory . '/site/site.css', $siteCss);
    file_put_contents($this->directory . '/site/site.js', $siteJs);

    $config = new ConfigLoader()->load($this->directory . '/site');
    new Publisher(engineRoot: $this->directory)->publish($this->directory, $config, $this->catalog());

    $publishedThemeCss = '@layer theme{:root{color: red;}}';
    $publishedSiteCss = '@layer overrides{:root{color: blue;}}';
    $assets = [
        'theme.' . hash('xxh3', $publishedThemeCss) . '.css' => $publishedThemeCss,
        'theme.' . hash('xxh3', $themeJs) . '.js' => $themeJs,
        'site.' . hash('xxh3', $publishedSiteCss) . '.css' => $publishedSiteCss,
        'site.' . hash('xxh3', $siteJs) . '.js' => $siteJs,
    ];
    $html = file_get_contents($this->directory . '/public/index.html');
    assert(is_string($html));

    foreach ($assets as $filename => $contents) {
        expect(file_get_contents($this->directory . '/public/assets/' . $filename))->toBe($contents)
            ->and($html)->toContain('/assets/' . $filename);
    }

    expect(glob($this->directory . '/public/assets/{theme,site}.{css,js}', GLOB_BRACE))->toBe([]);
});

it('accepts the exact retained entry asset ceiling and rejects one byte above it', function (int $limit, ?string $message): void {
    $this->content();
    $this->resources();
    file_put_contents($this->directory . '/resources/theme.css', 'abc');
    file_put_contents($this->directory . '/resources/theme.js', 'de');
    $config = new ConfigLoader()->load($this->directory . '/site');
    $load = fn(): PublicationResources => new Publisher(engineRoot: $this->directory)->validatedResources(
        $this->directory,
        $config,
        new Limits(retainedEntryAssetBytes: $limit),
    );

    if ($message === null) {
        $resources = $load();
        expect($resources->assets->themeStylesheet->bytes() + $resources->assets->themeScript->bytes())->toBe(5);

        return;
    }

    expect($load)->toThrow(ContentException::class, $message);
})->with([
    'exact aggregate limit' => [5, null],
    'one byte above aggregate limit' => [4, '4-byte retained-entry-asset ceiling'],
]);

it('enforces the retained entry asset ceiling against minified bytes', function (): void {
    $this->content();
    $this->resources();
    file_put_contents($this->directory . '/resources/theme.css', 'a   { }');
    file_put_contents($this->directory . '/resources/theme.js', 'de');
    $config = new ConfigLoader()->load($this->directory . '/site');

    $resources = new Publisher(engineRoot: $this->directory)->validatedResources(
        $this->directory,
        $config,
        new Limits(retainedEntryAssetBytes: 5),
    );
    expect($resources->assets->themeStylesheet->contents)->toBe('a{}')
        ->and(fn(): PublicationResources => new Publisher(engineRoot: $this->directory)->validatedResources(
            $this->directory,
            $config,
            new Limits(retainedEntryAssetBytes: 4),
        ))->toThrow(ContentException::class, '4-byte retained-entry-asset ceiling');
});

it('validates every configured site asset in the retained resource snapshot', function (): void {
    $this->content();
    $this->resources();
    mkdir($this->directory . '/site/assets');
    file_put_contents($this->directory . '/site/assets/declared.txt', 'asset');
    $config = new ConfigLoader()->load($this->directory . '/site');
    unlink($this->directory . '/site/assets/declared.txt');

    expect(fn(): PublicationResources => new Publisher(engineRoot: $this->directory)->validatedResources($this->directory, $config))
        ->toThrow(ContentException::class, "site/assets/declared.txt' must be a regular non-symlink file");
});

it('requires UTF-8 text at each publication entry asset boundary', function (string $relativePath): void {
    $this->content();
    $this->resources();
    file_put_contents($this->directory . '/site/site.css', 'body{}');
    file_put_contents($this->directory . '/site/site.js', 'true;');
    $config = new ConfigLoader()->load($this->directory . '/site');
    $path = $this->directory . '/' . $relativePath;
    file_put_contents($path, "\xFF");

    expect(fn(): PublicationResources => new Publisher(engineRoot: $this->directory)->validatedResources($this->directory, $config))
        ->toThrow(ContentException::class, "Publication asset '{$path}' must be readable UTF-8 text.");
})->with([
    'favicon' => 'site/favicon.svg',
    'theme CSS' => 'resources/theme.css',
    'theme JavaScript' => 'resources/theme.js',
    'site CSS' => 'site/site.css',
    'site JavaScript' => 'site/site.js',
]);

it('accepts the exact source asset limit before minification and rejects the next byte', function (bool $overLimit): void {
    $this->content();
    $this->resources();
    file_put_contents($this->directory . '/resources/theme.css', $overLimit ? "body{}\n" : 'body{}');
    file_put_contents($this->directory . '/resources/theme.js', 'true;');
    file_put_contents($this->directory . '/site/favicon.svg', '<svg/>');
    $config = new ConfigLoader()->load($this->directory . '/site');
    $load = fn(): PublicationResources => new Publisher(engineRoot: $this->directory)->validatedResources(
        $this->directory,
        $config,
        new Limits(assetBytes: 6),
    );

    if ($overLimit) {
        expect($load)->toThrow(ContentException::class, "resources/theme.css' exceeds the 6-byte asset limit.");

        return;
    }

    expect($load()->assets->themeStylesheet->contents)->toBe('body{}');
})->with(['exact limit' => false, 'one byte over' => true]);

it('reuses a complete validated resource snapshot without reopening its sources', function (): void {
    $this->content();
    $this->resources();
    $config = new ConfigLoader()->load($this->directory . '/site');
    $publisher = new Publisher(engineRoot: $this->directory);
    $resources = $publisher->validatedResources($this->directory, $config);
    unlink($this->directory . '/resources/templates/layout.html');
    unlink($this->directory . '/resources/theme.css');
    unlink($this->directory . '/resources/theme.js');

    $publisher->publish($this->directory, $config, $this->catalog(), templates: $resources->templates, assets: $resources->assets);

    expect(file_get_contents($this->directory . '/public/index.html'))->toContain('Generated and published with');
    foreach ($resources->assets->all() as $asset) {
        expect(file_get_contents($this->directory . '/public' . $asset->publishedPath))->toBe($asset->contents);
    }
});

it('keeps each caller-supplied publication resource when loading its missing peer', function (string $supplied): void {
    $this->content();
    $this->resources();
    $config = new ConfigLoader()->load($this->directory . '/site');
    $publisher = new Publisher(engineRoot: $this->directory);
    $original = $publisher->validatedResources($this->directory, $config);

    if ($supplied === 'templates') {
        $layoutPath = $this->directory . '/resources/templates/layout.html';
        $layout = file_get_contents($layoutPath);
        assert(is_string($layout));
        file_put_contents($layoutPath, str_replace('Generated and published with', 'New layout marker', $layout));
        $publisher->publish($this->directory, $config, $this->catalog(), templates: $original->templates);
        $html = file_get_contents($this->directory . '/public/index.html');
        expect($html)->toBeString()->toContain('Generated and published with')->not->toContain('New layout marker');

        return;
    }

    $oldThemeScript = $original->assets->themeScript;
    file_put_contents($this->directory . '/resources/theme.js', 'window.changed = true;');
    $publisher->publish($this->directory, $config, $this->catalog(), assets: $original->assets);

    expect(file_get_contents($this->directory . '/public' . $oldThemeScript->publishedPath))->toBe($oldThemeScript->contents)
        ->and(glob($this->directory . '/public/assets/theme.*.js'))->toBe([
            $this->directory . '/public' . $oldThemeScript->publishedPath,
        ]);
})->with(['templates', 'assets']);

it('closes minification streams on success and when output allocation fails', function (bool $failOutput, int $closes): void {
    $this->content();
    $this->resources();
    if ($failOutput) {
        PublisherFaults::set('publishing_fopen', ['pass', 'fail']);
    }
    $config = new ConfigLoader()->load($this->directory . '/site');

    try {
        (void) new Publisher(engineRoot: $this->directory)->validatedResources($this->directory, $config);
    } catch (ContentException $contentException) {
        expect($contentException->getMessage())->toContain('Unable to minify publication stylesheet');
    }

    expect(PublisherFaults::calls('publishing_fclose'))->toBe($closes);
})->with([
    'successful minification' => [false, 2],
    'failed output allocation' => [true, 1],
]);

it('fails a pathological direct build actionably under a 128 MiB memory limit', function (): void {
    $this->content();
    $this->resources();
    file_put_contents($this->directory . '/site/site.css', 'x');
    file_put_contents($this->directory . '/site/site.js', 'x');
    $maximum = new Limits()->assetBytes;
    assert($maximum >= 0);
    foreach (['resources/theme.css', 'resources/theme.js', 'site/site.css', 'site/site.js'] as $relativePath) {
        $stream = fopen($this->directory . '/' . $relativePath, 'c+b');
        expect($stream)->toBeResource();
        assert(is_resource($stream));
        expect(ftruncate($stream, $maximum))->toBeTrue();
        fclose($stream);
    }

    $root = dirname(__DIR__, 2);
    $process = proc_open(
        [PHP_BINARY, '-d', 'memory_limit=128M', $root . '/bin/snippet', 'build'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $this->directory,
    );
    expect($process)->toBeResource();
    assert(is_resource($process));
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    expect(proc_close($process))->toBe(1)
        ->and($stdout)->toBe('')
        ->and($stderr)->toContain('retained-entry-asset ceiling')
        ->not->toContain('Allowed memory size', 'Fatal error');
});

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
    $publisher = new Publisher(engineRoot: $this->directory);
    $resources = $publisher->validatedResources($this->directory, $config);
    $publisher->publish($this->directory, $config, $this->catalog(), templates: $resources->templates, assets: $resources->assets);
    $html = file_get_contents($this->directory . '/public/index.html');
    assert(is_string($html));
    $paths = $resources->assets->paths;

    expect($this->directory . '/public' . $paths->themeStylesheet)->toBeFile()
        ->and($this->directory . '/public' . $paths->themeScript)->toBeFile()
        ->and($paths->siteStylesheet !== null)->toBe($stylesheet)
        ->and($paths->siteScript !== null)->toBe($script)
        ->and($html)->toContain('<link rel="stylesheet" href="' . $paths->themeStylesheet . '">', '<script src="' . $paths->themeScript . '"></script>');

    if ($stylesheet) {
        assert(is_string($paths->siteStylesheet));
        $themePosition = mb_strpos($html, $paths->themeStylesheet);
        $sitePosition = mb_strpos($html, $paths->siteStylesheet);
        assert(is_int($themePosition));
        assert(is_int($sitePosition));
        expect(file_get_contents($this->directory . '/public' . $paths->siteStylesheet))->toBe('@layer overrides{:root{--custom: yes;}}')
            ->and($html)->toContain('<link rel="stylesheet" href="' . $paths->siteStylesheet . '">')
            ->and($themePosition)->toBeLessThan($sitePosition);
    } else {
        expect($html)->not->toMatch('~<link rel="stylesheet" href="/assets/site\.[0-9a-f]{16}\.css">~');
    }

    if ($script) {
        assert(is_string($paths->siteScript));
        $themePosition = mb_strpos($html, $paths->themeScript);
        $sitePosition = mb_strpos($html, $paths->siteScript);
        assert(is_int($themePosition));
        assert(is_int($sitePosition));
        expect(file_get_contents($this->directory . '/public' . $paths->siteScript))->toBe($siteJs)
            ->and($html)->toContain('<script src="' . $paths->siteScript . '" defer></script>')
            ->and($themePosition)->toBeLessThan($sitePosition);
    } else {
        expect($html)->not->toMatch('~<script src="/assets/site\.[0-9a-f]{16}\.js" defer></script>~');
    }

    expect($html)->not->toContain('legacy resource', 'legacy site')
        ->and($this->directory . '/public' . $paths->themeStylesheet)->toBeFile();
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
    new Publisher(engineRoot: $this->directory)->publish($this->directory, $config, $this->catalog());
    $page = file_get_contents($this->directory . '/public/page/index.html');
    $css = file_get_contents($this->publishedAsset('theme.css'));
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

it('exposes only present customization assets to internal reference validation', function (): void {
    $this->content();
    $this->resources();
    file_put_contents($this->directory . '/site/site.js', '/* custom */');
    $inputs = new PublicationInputLoader(publisher: new Publisher(engineRoot: $this->directory))->load($this->directory);
    $paths = new PublicationInventory($inputs->config, $inputs->catalog, $inputs->assets->paths)->paths();

    expect($paths)->toContain($inputs->assets->themeStylesheet->publishedPath, $inputs->assets->themeScript->publishedPath, $inputs->assets->siteScript?->publishedPath)
        ->not->toContain('/assets/site.css');
});

it('counts every retained, generated, site, and content asset exactly once', function (): void {
    $article = $this->article('post', [
        'title' => 'Post',
        'description' => 'Description.',
        'date' => '2026-01-01',
        'tags' => ['Café'],
    ]);
    $page = $this->item('about', ['title' => 'About', 'description' => 'Description.']);
    file_put_contents($article . '/notes.txt', 'notes');
    file_put_contents($page . '/handout.pdf', 'PDF');
    mkdir($this->directory . '/site/assets/downloads', 0777, true);
    file_put_contents($this->directory . '/site/assets/downloads/guide.pdf', 'PDF');
    file_put_contents($this->directory . '/site/site.css', '/* custom */');
    file_put_contents($this->directory . '/site/site.js', '/* custom */');
    $this->resources();

    $inputs = new PublicationInputLoader(publisher: new Publisher(engineRoot: $this->directory))->load($this->directory);

    expect($inputs->assetCount())->toBe(8)
        ->and($inputs->assets->all())->toBe([
            $inputs->assets->themeStylesheet,
            $inputs->assets->themeScript,
            $inputs->assets->siteStylesheet,
            $inputs->assets->siteScript,
        ]);
});

it('exposes the complete publication inventory as one exact ordered snapshot', function (): void {
    $article = $this->article('post', [
        'title' => 'Post',
        'description' => 'Description.',
        'date' => '2026-01-01',
        'tags' => ['Café'],
    ]);
    $page = $this->item('about', ['title' => 'About', 'description' => 'Description.']);
    file_put_contents($article . '/notes.txt', 'notes');
    file_put_contents($page . '/handout.pdf', 'PDF');
    mkdir($this->directory . '/site/assets/downloads', 0777, true);
    file_put_contents($this->directory . '/site/assets/downloads/guide.pdf', 'PDF');
    file_put_contents($this->directory . '/site/site.css', '/* custom */');
    file_put_contents($this->directory . '/site/site.js', '/* custom */');
    $this->resources();

    $inputs = new PublicationInputLoader(publisher: new Publisher(engineRoot: $this->directory))->load($this->directory);
    assert($inputs->assets->siteStylesheet instanceof PublicationAsset);
    assert($inputs->assets->siteScript instanceof PublicationAsset);
    $expected = [
        '/',
        '/404.html',
        '/about/',
        '/about/handout.pdf',
        '/about/index.html',
        '/articles/',
        '/articles/index.html',
        '/articles/post/',
        '/articles/post/index.html',
        '/articles/post/notes.txt',
        '/assets/site/downloads/guide.pdf',
        $inputs->assets->siteStylesheet->publishedPath,
        $inputs->assets->siteScript->publishedPath,
        $inputs->assets->themeStylesheet->publishedPath,
        $inputs->assets->themeScript->publishedPath,
        '/favicon.svg',
        '/index.html',
        '/llms.txt',
        '/pages/',
        '/pages/index.html',
        '/tags/',
        '/tags/caf%C3%A9/',
        '/tags/caf%C3%A9/index.html',
        '/tags/index.html',
    ];
    sort($expected, SORT_STRING);

    $inventory = new PublicationInventory($inputs->config, $inputs->catalog, $inputs->assets->paths);

    expect($inventory->paths())->toBe($expected);
    foreach ($expected as $path) {
        expect($inventory->contains($path))->toBeTrue();
    }

    expect($inventory->contains('/'))
        ->toBeTrue()
        ->and($inventory->contains('/tags/café/'))->toBeTrue()
        ->and($inventory->contains('/tags/caf%C3%A9/'))->toBeTrue()
        ->and($inventory->contains('/missing/'))->toBeFalse();
});

it('keeps script source ceilings and the previous publication when preparation fails', function (string $failure): void {
    $this->content();
    $this->resources();
    file_put_contents($this->directory . '/resources/theme.css', 'x');
    file_put_contents($this->directory . '/resources/theme.js', 'x');
    file_put_contents($this->directory . '/site/favicon.svg', '<svg/>');
    file_put_contents($this->directory . '/site/site.js', 'x');
    $config = new ConfigLoader()->load($this->directory . '/site');
    $publisher = new Publisher(engineRoot: $this->directory);
    $publisher->publish($this->directory, $config, $this->catalog());

    $original = file_get_contents($this->directory . '/public/index.html');
    file_put_contents($this->directory . '/site/site.js', str_repeat(' ', 40));
    $limits = match ($failure) {
        'source' => new Limits(assetBytes: 39),
        'retained' => new Limits(retainedEntryAssetBytes: 41),
        default => new Limits(),
    };
    if ($failure === 'read') {
        PublisherFaults::set('publishing_file_get_contents', ['pass', 'fail']);
    }

    expect(fn(): BuildReport => $publisher->publish($this->directory, $config, $this->catalog(), $limits))
        ->toThrow(ContentException::class, match ($failure) {
            'source' => "site/site.js' exceeds the 39-byte asset limit",
            'retained' => 'retained-entry-asset ceiling',
            default => 'Unable to read publication asset',
        })
        ->and(file_get_contents($this->directory . '/public/index.html'))->toBe($original)
        ->and(glob($this->directory . '/.snippet-build-*'))->toBe([]);
})->with(['source', 'retained', 'read']);

it('publishes scripts unchanged with their original fingerprint', function (): void {
    $this->content();
    $this->resources();
    $script = 'const   ratio = value / 2;';
    file_put_contents($this->directory . '/site/site.js', $script);
    $config = new ConfigLoader()->load($this->directory . '/site');
    $publisher = new Publisher(engineRoot: $this->directory);
    $publisher->publish($this->directory, $config, $this->catalog());

    expect(file_get_contents($this->directory . '/public/assets/site.' . hash('xxh3', $script) . '.js'))->toBe($script);
});
