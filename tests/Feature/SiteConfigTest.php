<?php

declare(strict_types=1);

use Snippet\Exception\ContentException;
use Snippet\Site\Config;
use Snippet\Site\ConfigLoader;

function loadSiteConfig(string $directory): Config
{
    return new ConfigLoader()->load($directory . '/site');
}

it('loads exact document, wordmark, and author identities with optional presentation files', function (): void {
    mkdir($this->directory . '/site/assets/media', 0777, true);
    file_put_contents($this->directory . '/site/assets/media/custom.bin', "asset\0bytes");
    file_put_contents($this->directory . '/site/theme.css', '@layer overrides {}');
    $this->site([
        'title' => 'Document Identity',
        'sitename' => '日本語 Wordmark',
        'author' => '日本語 Writer',
    ]);

    $config = loadSiteConfig($this->directory);

    expect($config->title)->toBe('Document Identity')
        ->and($config->sitename)->toBe('日本語 Wordmark')
        ->and($config->author)->toBe('日本語 Writer')
        ->and($config->url)->toBe('https://example.test')
        ->and($config->basePath)->toBeEmpty()
        ->and($config->publicPath('/articles/'))->toBe('/articles/')
        ->and($config->canonicalUrl('/articles/'))->toBe('https://example.test/articles/')
        ->and($config->origin())->toBe('https://example.test')
        ->and($config->language)->toBe('en')
        ->and($config->assets)->toBe(['media/custom.bin'])
        ->and($config->hasTheme)->toBeTrue();
});

it('accepts an absent assets directory and theme', function (): void {
    $config = loadSiteConfig($this->directory);
    expect($config->assets)->toBeEmpty()
        ->and($config->hasTheme)->toBeFalse();
});

it('treats the exact site configuration shape as order-independent', function (): void {
    file_put_contents($this->directory . '/site/config.php', <<<'PHP'
<?php
declare(strict_types=1);

return [
    'language' => 'en',
    'url' => 'https://example.test',
    'description' => 'Description.',
    'author' => 'Writer',
    'sitename' => 'Wordmark',
    'title' => 'Title',
    'build' => ['minify' => true],
    'home' => ['articles' => 1, 'tags' => 2],
];
PHP);

    expect(loadSiteConfig($this->directory)->title)->toBe('Title');
});

it('accepts root and path-hosted HTTPS site URLs', function (string $url, string $basePath): void {
    $this->site(['url' => $url]);

    $config = loadSiteConfig($this->directory);

    expect($config->url)->toBe($url)
        ->and($config->basePath)->toBe($basePath)
        ->and($config->publicPath('/articles/post/'))->toBe($basePath . '/articles/post/')
        ->and($config->canonicalUrl('/articles/post/'))->toBe($url . '/articles/post/')
        ->and($config->origin())->toBe('https://example.test');
})->with([
    'root' => ['https://example.test', ''],
    'project' => ['https://example.test/snippet', '/snippet'],
    'multiple segments' => ['https://example.test/work/sites/snippet', '/work/sites/snippet'],
    'encoded segment' => ['https://example.test/caf%C3%A9', '/caf%C3%A9'],
]);

it('rejects malformed HTTPS site URLs', function (mixed $url): void {
    $this->site(['url' => $url]);
    loadSiteConfig($this->directory);
})->throws(ContentException::class, 'HTTPS site URL')->with([
    null,
    'http://example.test',
    'https://user@example.test',
    'https://example.test?query',
    'https://example.test#fragment',
    'https://example.test/',
    'https://_',
    'https://example.test/two//segments',
    'https://example.test/./segment',
    'https://example.test/%2e%2e/segment',
    'https://example.test/encoded%2Fseparator',
    'https://example.test/bad%escape',
]);
it('rejects invalid text and language fields', function (array $overrides, string $message, bool $escapedInvalidUtf8 = false): void {
    /** @var array<string, mixed> $overrides */
    $this->site($overrides);
    if ($escapedInvalidUtf8) {
        $path = $this->directory . '/site/config.php';
        $source = file_get_contents($path);
        assert(is_string($source));
        file_put_contents($path, str_replace(
            "'sitename' => 'Test Site'",
            "'sitename' => \"bad\\xFF\"",
            $source,
        ));
    }
    loadSiteConfig($this->directory);
})->throws(ContentException::class)->with([
    [['title' => ''], 'title'],
    [[], 'sitename', true],
    [['sitename' => ''], 'sitename'],
    [['sitename' => ' padded '], 'sitename'],
    [['sitename' => 8], 'sitename'],
    [['author' => ''], 'author'],
    [['author' => ' padded '], 'author'],
    [['author' => 8], 'author'],
    [['description' => ' padded '], 'description'],
    [['language' => 'e'], 'language'],
    [['language' => 8], 'language'],
]);

it('rejects an author that is not valid UTF-8', function (): void {
    $this->site();
    $path = $this->directory . '/site/config.php';
    $source = file_get_contents($path);
    assert(is_string($source));
    file_put_contents($path, str_replace(
        "'author' => 'Test Author'",
        "'author' => \"bad\\xFF\"",
        $source,
    ));

    loadSiteConfig($this->directory);
})->throws(ContentException::class, "field 'author'");

it('rejects invalid outer PHP configuration contracts', function (string $source, string $message): void {
    file_put_contents($this->directory . '/site/config.php', $source);
    loadSiteConfig($this->directory);
})->throws(ContentException::class)->with([
    ["<?php return [];", 'strict_types'],
    ["<?php declare(strict_types=1); echo 'bad'; return [];", 'output'],
    ["<?php declare(strict_types=1); throw new RuntimeException('broken');", 'broken'],
    ["<?php declare(strict_types=1); return 'bad';", 'exact fields'],
    ["<?php declare(strict_types=1); return [0 => 'bad'];", 'exact fields'],
    ["<?php declare(strict_types=1); return ['title' => 'Title', 'author' => 'Writer', 'description' => 'Description.', 'url' => 'https://example.test', 'language' => 'en', 'home' => ['articles' => 1, 'tags' => 1], 'build' => ['minify' => false]];", 'exact fields'],
    ["<?php declare(strict_types=1); return ['title' => 'Title', 'sitename' => 'Wordmark', 'description' => 'Description.', 'url' => 'https://example.test', 'language' => 'en', 'home' => ['articles' => 1, 'tags' => 1], 'build' => ['minify' => false]];", 'exact fields'],
]);

it('reports a missing configuration file', function (): void {
    unlink($this->directory . '/site/config.php');
    loadSiteConfig($this->directory);
})->throws(ContentException::class, 'does not exist');

it('rejects symlinks and unsupported entries in site presentation files', function (string $kind): void {
    if ($kind === 'theme') {
        symlink($this->directory . '/site/config.php', $this->directory . '/site/theme.css');
    } elseif ($kind === 'asset') {
        mkdir($this->directory . '/site/assets');
        symlink($this->directory . '/site/config.php', $this->directory . '/site/assets/link');
    } else {
        mkdir($this->directory . '/site/assets');
        expect(posix_mkfifo($this->directory . '/site/assets/pipe', 0600))->toBeTrue();
    }

    loadSiteConfig($this->directory);
})->throws(ContentException::class)->with(['theme', 'asset', 'fifo']);

it('rejects a symlinked site assets root', function (): void {
    $assets = $this->directory . '/site/assets';
    symlink($this->directory . '/site', $assets);

    expect(fn(): Config => loadSiteConfig($this->directory))
        ->toThrow(ContentException::class, "Site assets directory '{$assets}' must be a regular non-symlink directory.");
});

it('rejects invalid UTF-8 themes', function (): void {
    file_put_contents($this->directory . '/site/theme.css', "bad\xFF");
    loadSiteConfig($this->directory);
})->throws(ContentException::class, 'UTF-8');

it('rejects invalid exact home and build configuration', function (array $overrides, string $message): void {
    /** @var array<string, mixed> $overrides */
    $this->site($overrides);
    loadSiteConfig($this->directory);
})->throws(ContentException::class)->with([
    'missing home field' => [['home' => ['articles' => 1]], 'exact articles and tags'],
    'unknown home field' => [['home' => ['articles' => 1, 'tags' => 1, 'extra' => 1]], 'exact articles and tags'],
    'non-array home' => [['home' => null], 'exact articles and tags'],
    'zero articles' => [['home' => ['articles' => 0, 'tags' => 1]], 'positive integers'],
    'boolean tags' => [['home' => ['articles' => 1, 'tags' => true]], 'positive integers'],
    'missing build field' => [['build' => []], 'exact minify'],
    'unknown build field' => [['build' => ['minify' => false, 'extra' => false]], 'exact minify'],
    'non-array build' => [['build' => false], 'exact minify'],
    'non-boolean minify' => [['build' => ['minify' => 0]], 'boolean'],
]);
