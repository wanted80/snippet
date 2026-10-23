<?php

declare(strict_types=1);

use Snippet\Exception\ContentException;
use Snippet\Site\Config;
use Snippet\Site\ConfigLoader;

mutates(Config::class, ConfigLoader::class);

it('preserves the default policy when CSP additions are absent or empty', function (bool $configured): void {
    $this->site($configured ? ['csp' => []] : []);
    $config = new ConfigLoader()->load($this->directory . '/site');

    expect($config->csp)->toBeEmpty()
        ->and($config->contentSecurityPolicy())->toBe("default-src 'none'; base-uri 'none'; form-action 'none'; script-src 'self'; style-src 'self'; img-src 'self'; font-src 'self'; connect-src 'self'");
})->with([
    'omitted' => false,
    'empty' => true,
]);

it('adds only the configured HTTPS origins in a stable directive order', function (): void {
    $this->site(['csp' => [
        'connect-src' => ['https://events.example.test:8443'],
        'script-src' => ['https://cdn.example.test', 'https://scripts.example.test'],
        'style-src' => [],
        'img-src' => ['https://images.example.test'],
        'font-src' => ['https://fonts.example.test'],
    ]]);
    $loader = new ConfigLoader();
    $config = $loader->load($this->directory . '/site');

    expect($config->contentSecurityPolicy())->toBe("default-src 'none'; base-uri 'none'; form-action 'none'; script-src 'self' https://cdn.example.test https://scripts.example.test; style-src 'self'; img-src 'self' https://images.example.test; font-src 'self' https://fonts.example.test; connect-src 'self' https://events.example.test:8443")
        ->and(serialize($loader->load($this->directory . '/site')))->toBe(serialize($config));
});

it('omits the meta policy from every document when the host manages CSP', function (): void {
    $this->site(['csp' => false]);
    $loader = new ConfigLoader();
    $config = $loader->load($this->directory . '/site');
    expect($config->csp)->toBeFalse()
        ->and($config->contentSecurityPolicy())->toBeNull()
        ->and(serialize($loader->load($this->directory . '/site')))->toBe(serialize($config));

    $this->item('about', ['title' => 'About', 'description' => 'About.']);
    $this->article('post', ['title' => 'Post', 'description' => 'Post.', 'date' => '2026-10-18', 'tags' => ['PHP']]);
    [$status, , $error] = validatePublication($this->directory, 'build');
    expect($status)->toBe(0, $error);

    foreach (['index.html', '404.html', 'articles/index.html', 'pages/index.html', 'tags/index.html', 'about/index.html', 'articles/post/index.html', 'tags/php/index.html'] as $path) {
        $html = file_get_contents($this->directory . '/public/' . $path);
        assert(is_string($html));
        expect($html)->not->toContain('Content-Security-Policy', '{{csp}}')
            ->toContain('<meta charset="utf-8">', '<script src="', '<link rel="stylesheet"');
    }
});

it('publishes the configured policy before scripts on every generated document', function (): void {
    $this->site(['csp' => ['script-src' => ['https://cdn.example.test'], 'connect-src' => ['https://events.example.test']]]);
    $this->item('about', ['title' => 'About', 'description' => 'About.']);
    $this->article('post', ['title' => 'Post', 'description' => 'Post.', 'date' => '2026-10-18', 'tags' => ['PHP']]);
    file_put_contents($this->directory . '/site/site.js', "const script = document.createElement('script');\nscript.src = 'https://cdn.example.test/widget.js';\ndocument.head.append(script);\n");
    [$status, , $error] = validatePublication($this->directory, 'build');
    expect($status)->toBe(0, $error);

    foreach (['index.html', '404.html', 'articles/index.html', 'pages/index.html', 'tags/index.html', 'about/index.html', 'articles/post/index.html', 'tags/php/index.html'] as $path) {
        $html = file_get_contents($this->directory . '/public/' . $path);
        assert(is_string($html));
        expect($html)->toMatch('~<meta http-equiv="Content-Security-Policy" content="default-src \'none\'; base-uri \'none\'; form-action \'none\'; script-src \'self\' https://cdn.example.test; style-src \'self\'; img-src \'self\'; font-src \'self\'; connect-src \'self\' https://events.example.test">.*<script src="~s')
            ->not->toContain('{{csp}}');
    }
});

it('rejects malformed CSP additions with a field-specific error', function (mixed $csp): void {
    $this->site(['csp' => $csp]);

    expect(fn(): Config => new ConfigLoader()->load($this->directory . '/site'))->toThrow(ContentException::class, 'csp');
})->with([
    'null' => [null],
    'true' => [true],
    'zero' => [0],
    'one' => [1],
    'false string' => ['false'],
    'string' => ["script-src https://cdn.example.test"],
    'list' => [['https://cdn.example.test']],
    'unknown directive' => [['script-scr' => []]],
    'default override' => [['default-src' => ['https://cdn.example.test']]],
    'base override' => [['base-uri' => ['https://cdn.example.test']]],
    'form override' => [['form-action' => ['https://cdn.example.test']]],
    'meta unsupported directive' => [['frame-ancestors' => ["'none'"]]],
    'null sources' => [['script-src' => null]],
    'string sources' => [['script-src' => 'https://cdn.example.test']],
    'associative sources' => [['script-src' => ['cdn' => 'https://cdn.example.test']]],
    'too many sources' => [['script-src' => array_fill(0, 33, 'https://cdn.example.test')]],
]);

it('rejects CSP sources that are not explicit HTTPS origins', function (mixed $source): void {
    $this->site(['csp' => ['script-src' => [$source]]]);

    expect(fn(): Config => new ConfigLoader()->load($this->directory . '/site'))->toThrow(ContentException::class, "csp.script-src");
})->with([
    'null' => [null],
    'integer' => [1],
    'array' => [[]],
    'empty' => [''],
    'http' => ['http://cdn.example.test'],
    'scheme only' => ['https:'],
    'protocol relative' => ['//cdn.example.test'],
    'wildcard' => ['*'],
    'wildcard host' => ['https://*.example.test'],
    'inline' => ["'unsafe-inline'"],
    'eval' => ["'unsafe-eval'"],
    'self' => ["'self'"],
    'data' => ['data:'],
    'credentials' => ['https://user:pass@cdn.example.test'],
    'slash' => ['https://cdn.example.test/'],
    'path' => ['https://cdn.example.test/widget.js'],
    'query' => ['https://cdn.example.test?key=value'],
    'fragment' => ['https://cdn.example.test#fragment'],
    'leading space' => [' https://cdn.example.test'],
    'trailing space' => ['https://cdn.example.test '],
    'newline' => ["https://cdn.example.test\n"],
    'control' => ["https://cdn.\texample.test"],
    'directive injection' => ["https://cdn.example.test; script-src *"],
    'attribute injection' => ['https://cdn.example.test" onload="alert(1)'],
    'invalid host' => ['https://-invalid.example.test'],
    'invalid port' => ['https://cdn.example.test:65536'],
]);

it('accepts the CSP source ceiling and explicit HTTPS ports', function (): void {
    $sources = array_map(static fn(int $index): string => 'https://cdn' . $index . '.example.test:443', range(1, 32));
    $this->site(['csp' => ['script-src' => $sources]]);

    expect(new ConfigLoader()->load($this->directory . '/site')->csp)->toBe(['script-src' => $sources]);
});

it('leaves the last valid publication intact after an invalid CSP edit', function (): void {
    $this->content();
    [$status, , $error] = validatePublication($this->directory, 'build');
    expect($status)->toBe(0, $error);
    $before = file_get_contents($this->directory . '/public/index.html');
    $this->site(['csp' => ['script-src' => ['*']]]);

    [$status, , $error] = validatePublication($this->directory, 'build');
    expect($status)->toBe(1)
        ->and($error)->toContain('csp.script-src')
        ->and(file_get_contents($this->directory . '/public/index.html'))->toBe($before);
});
