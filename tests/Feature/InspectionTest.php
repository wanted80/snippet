<?php

declare(strict_types=1);

use Snippet\Content\ContentType;
use Snippet\Exception\ContentException;
use Snippet\Inspection\Inspector;
use Snippet\Inspection\ThemeContract;
use Snippet\Site\ConfigLoader;
use Snippet\Support\TrustedPhpLoader;

mutates(ThemeContract::class);

it('inspects installed contracts independently of invalid workspace files', function (string $subject): void {
    file_put_contents($this->directory . '/site/config.php', '<?php throw new Exception();');
    file_put_contents($this->directory . '/site/site.css', ':root { --color-accent: red; }');
    [$status, $value, , $bytes] = runAgentCli($this->directory, ['inspect', $subject, '--json']);
    expect($status)->toBe(0)->and($value['subject'])->toBe($subject)
        ->and(runAgentCli($this->directory, ['inspect', $subject, '--json'])[3])->toBe($bytes)
        ->and($this->directory . '/public')->not->toBeDirectory();
})->with(['capabilities', 'theme', 'config', 'content']);

it('exposes exactly the documented theme tokens hooks and layers with canonical expressions', function (): void {
    $result = new Inspector(dirname(__DIR__, 2))->inspect('theme');
    $defaults = $result['engine_defaults'];
    assert(is_array($defaults));
    $colors = $defaults['colors'];
    $fonts = $defaults['fonts'];
    $sizing = $defaults['sizing'];
    assert(is_array($colors) && is_array($fonts) && is_array($sizing));
    expect(array_keys($colors))->toBe(['--color-background', '--color-surface', '--color-interactive', '--color-text', '--color-muted', '--color-accent', '--color-border', '--color-header-background', '--color-navigation-background', '--color-header-button-background', '--color-navigation-item-background', '--color-on-accent'])
        ->and(array_keys($fonts))->toBe(['--font-reading', '--font-interface', '--font-wordmark', '--font-code'])
        ->and(array_keys($sizing))->toBe(['--measure-prose', '--measure-shell', '--space-1', '--space-2', '--space-3', '--space-4', '--space-5', '--space-6', '--space-section', '--radius-control', '--radius-panel'])
        ->and(array_keys($defaults))->toBe(['colors', 'fonts', 'sizing', 'effects'])
        ->and($defaults['effects'])->toBe([
            '--opacity-header-background' => '82%',
            '--opacity-navigation-background' => '82%',
            '--shadow-header' => '0 0.25rem 0.9rem light-dark(rgb(0 0 0 / 10%), rgb(0 0 0 / 22%))',
            '--shadow-menu' => '0 1rem 2.5rem light-dark(rgb(0 0 0 / 18%), rgb(0 0 0 / 38%))',
            '--shadow-content' => '-1rem 0 2rem -1.35rem light-dark(rgb(0 0 0 / 24%), rgb(0 0 0 / 42%)), 1rem 0 2rem -1.35rem light-dark(rgb(0 0 0 / 24%), rgb(0 0 0 / 42%))',
        ])
        ->and($colors['--color-header-background'])->toBe('var(--color-surface)')
        ->and($colors['--color-on-accent'])->toBe('var(--color-background)')
        ->and($colors['--color-accent'])->toBe('light-dark(#8a3f2d, #9fc5ff)')
        ->and($fonts['--font-reading'])->toBe('ui-serif, Charter, "Bitstream Charter", "Sitka Text", Cambria, Georgia, serif')
        ->and($sizing['--space-section'])->toBe('clamp(5rem, 12vw, 7rem)')
        ->and($result['class_hooks'])->toBe(['.site-header', '.site-brand', '.site-wordmark', '.site-navigation', '.site-main', '.article-list', '.article-figure', '.content-header', '.prose', '.tag-list', '.site-footer'])
        ->and($result['layers'])->toBe(['reset', 'tokens', 'base', 'layout', 'components', 'overrides'])
        ->and($result['stylesheet'])->toBe('site/site.css');
});

it('shares configuration fields and safely parsed starter values', function (): void {
    $root = dirname(__DIR__, 2);
    $result = new Inspector($root)->inspect('config');
    expect($result['required'])->toBe(ConfigLoader::FIELDS)
        ->and($result['starter_values'])->toBe(new TrustedPhpLoader()->load($root . '/site/config.php', 'starter'));
    $content = new Inspector($root)->inspect('content');
    $types = $content['types'];
    assert(is_array($types));
    foreach (ContentType::cases() as $type) {
        $contract = $types[$type->value];
        assert(is_array($contract));
        expect($contract['required'])->toBe($type->metadataFields())
            ->and($contract['source'])->toBe($type->sourceFilename())
            ->and($contract['route'])->toBe($type === ContentType::Page ? '/<slug>/' : '/articles/<slug>/');
    }
});

it('advertises only commands available through the selected entrypoint', function (): void {
    $inspector = new Inspector(dirname(__DIR__, 2));
    $direct = $inspector->inspect('capabilities');
    $docker = $inspector->inspect('capabilities', true);
    expect($direct['commands'])->not->toHaveKey('init')
        ->and($docker['commands'])->toHaveKey('init')
        ->and($inspector->inspect('capabilities', false, false)['commands'])->not->toHaveKey('preview');
});

it('rejects incomplete duplicate and unsupported token declarations', function (string $before, string $after): void {
    $this->resources();
    $path = $this->directory . '/resources/theme.css';
    $css = file_get_contents($path);
    assert(is_string($css));
    file_put_contents($path, str_replace($before, $after, $css));
    expect(fn(): array => new Inspector($this->directory)->inspect('theme'))->toThrow(ContentException::class);
})->with([
    ['--space-1: 0.35rem;', ''],
    ['--space-1: 0.35rem;', '--space-1: 0.35rem; --space-1: 1rem;'],
    ['--space-1: 0.35rem;', 'color: red;'],
    ['--space-1: 0.35rem;', '--space-1: ;'],
    ['--space-1: 0.35rem;', '--space-1: 1rem !important;'],
    ['--space-1: 0.35rem;', '--space-1: calc(1rem;'],
    ['--space-1: 0.35rem;', '--space-1: 1rem);'],
    ['--space-1: 0.35rem;', '--space-1: calc(1rem));'],
    ['--space-1: 0.35rem;', '--space-1: ) (1rem;'],
    ['--font-interface: system-ui, sans-serif;', '--font-interface: "Unclosed font;'],
    ['--font-interface: system-ui, sans-serif;', "--font-interface: \"Invalid\nfont\";"],
    ['--font-interface: system-ui, sans-serif;', "--font-interface: \"Invalid\rfont\";"],
    ["--space-1: 0.35rem;\n        --space-2: 0.7rem;", '--space-1: calc(1rem; --space-2: 2rem);'],
    ["--space-1: 0.35rem;\n        --space-2: 0.7rem;", '--space-1: "Font; --space-2: Name";'],
    ['@layer tokens {', '@layer tokens { :root { --space-1: 1rem; } } @layer tokens {'],
    ['@layer tokens {', '@layer private {'],
    ['--space-1: 0.35rem;', '@media print { --space-1: 1rem; }'],
]);

it('requires an active top-level tokens layer', function (string $prefix, string $suffix): void {
    $css = file_get_contents(dirname(__DIR__, 2) . '/resources/theme.css');
    assert(is_string($css));
    expect(fn(): array => new ThemeContract()->defaults($prefix . $css . $suffix))->toThrow(ContentException::class);
})->with([
    'commented stylesheet' => ['/*', '*/'],
    'conditional stylesheet' => ['@media print {', '}'],
    'unclosed comment' => ['/*', ''],
    'unclosed block' => ['', 'a {'],
    'unmatched closing block' => ['', '}'],
    'reversed blocks' => ['', '}a{'],
    'overlapping comment delimiters' => ['/*/', ''],
]);

it('reads compact token blocks beside comments rules and layer statements', function (string $prefix): void {
    $declarations = '';
    $expected = [];
    foreach (ThemeContract::TOKENS as $group => $tokens) {
        $expected[$group] = array_fill_keys($tokens, '0');
        foreach ($tokens as $token) {
            $declarations .= $token . ':0;';
        }
    }
    expect(new ThemeContract()->defaults($prefix . '@layer tokens{:root{' . $declarations . '}}'))->toBe($expected);
})->with(['', '@layer reset,tokens;', '@charset "UTF-8";', 'a{}', '/**/', '/**/*{}', 'a/**/{}', '/* one *//* two */']);

it('reports a handled error when layer header parsing exhausts PCRE resources', function (): void {
    $limit = ini_get('pcre.backtrack_limit');
    assert(is_string($limit));
    ini_set('pcre.backtrack_limit', '1');
    try {
        expect(fn(): array => new ThemeContract()->defaults('/* ' . str_repeat('x', 1000) . ' */@layer tokens{:root{}}'))
            ->toThrow(ContentException::class, 'Installed theme contains an unsupported layer header.');
    } finally {
        ini_set('pcre.backtrack_limit', $limit);
    }
});

it('ignores token lookalikes in comments strings and conditional layers', function (): void {
    $css = file_get_contents(dirname(__DIR__, 2) . '/resources/theme.css');
    assert(is_string($css));
    $lookalikes = <<<'CSS'
        /* @layer tokens { :root { --space-1: 99rem; } } */
        a::before { content: "\" @layer tokens { :root { --space-1: 99rem; } }"; }
        a::after { content: '\' /* } );'; }
        @media print { @layer tokens { :root { --space-1: 99rem; } } }
        CSS;
    expect(new ThemeContract()->defaults($lookalikes . $css . $lookalikes))->toBe(new ThemeContract()->defaults($css));
});

it('preserves balanced expressions and quoted punctuation verbatim', function (): void {
    $css = file_get_contents(dirname(__DIR__, 2) . '/resources/theme.css');
    assert(is_string($css));
    $css = str_replace('--font-interface: system-ui, sans-serif;', '--font-interface: "Font (Reading)", \'Font )\', sans-serif;', $css);
    $css = str_replace('@layer tokens {', '@layer /* defaults */ tokens {', $css);

    expect(new ThemeContract()->defaults($css)['fonts']['--font-interface'])->toBe('"Font (Reading)", \'Font )\', sans-serif');
});

it('bounds installed theme reads and rejects unsafe paths', function (string $fault): void {
    $this->resources();
    $path = $this->directory . '/resources/theme.css';
    if ($fault === 'oversize') {
        file_put_contents($path, str_repeat(' ', 131073));
    } elseif ($fault === 'directory-link') {
        rename($this->directory . '/resources', $this->directory . '/original');
        symlink($this->directory . '/original', $this->directory . '/resources');
    } elseif ($fault === 'unreadable') {
        chmod($path, 0000);
    } elseif ($fault === 'encoding') {
        file_put_contents($path, "\xFF");
    } else {
        unlink($path);
        if ($fault === 'link') {
            symlink(dirname(__DIR__, 2) . '/resources/theme.css', $path);
        }
    }
    set_error_handler(null);
    try {
        expect(fn(): array => new Inspector($this->directory)->inspect('theme'))->toThrow(ContentException::class);
    } finally {
        restore_error_handler();
        if ($fault === 'unreadable') {
            chmod($path, 0644);
        }
    }
})->with(['oversize', 'directory-link', 'unreadable', 'encoding', 'missing', 'link']);

it('rejects unsupported inspection subjects at the service boundary', function (): void {
    expect(fn(): array => new Inspector($this->directory)->inspect('site'))->toThrow(InvalidArgumentException::class, "Unknown inspection subject 'site'.");
});

it('accepts the exact resource ceiling and ignores private variables and print overrides', function (): void {
    $this->resources();
    $path = $this->directory . '/resources/theme.css';
    $css = file_get_contents($path);
    assert(is_string($css));
    $css .= "\n@media print { :root { --color-accent: black; } }\n";
    file_put_contents($path, mb_str_pad($css, 131072));
    expect(new Inspector($this->directory)->inspect('theme'))->toBe(new Inspector(dirname(__DIR__, 2))->inspect('theme'));
});

it('rejects symlinked starter directories and declarative PHP violations without executing them', function (): void {
    $marker = $this->directory . '/executed';
    file_put_contents($this->directory . '/site/config.php', "<?php\ndeclare(strict_types=1); file_put_contents('{$marker}', 'yes'); return [];");
    expect(fn(): array => new Inspector($this->directory)->inspect('config'))->toThrow(ContentException::class)
        ->and($marker)->not->toBeFile();
    rename($this->directory . '/site', $this->directory . '/original');
    symlink($this->directory . '/original', $this->directory . '/site');
    expect(fn(): array => new Inspector($this->directory)->inspect('config'))->toThrow(ContentException::class);
});

it('measures the theme ceiling in bytes even when the excess is valid multibyte text', function (): void {
    $this->resources();
    $path = $this->directory . '/resources/theme.css';
    $css = file_get_contents($path);
    assert(is_string($css));
    $suffix = str_repeat('é', 100);
    file_put_contents($path, mb_str_pad($css, 131073 - mb_strlen($suffix, '8bit')) . $suffix);
    expect(fn(): array => new Inspector($this->directory)->inspect('theme'))->toThrow(ContentException::class);
});

it('keeps inspection independent from ambient multibyte encoding', function (): void {
    $inspector = new Inspector(dirname(__DIR__, 2));
    $expected = $inspector->inspect('theme');
    $encoding = mb_internal_encoding();
    try {
        mb_internal_encoding('UTF-16');
        expect($inspector->inspect('theme'))->toBe($expected);
    } finally {
        mb_internal_encoding($encoding);
    }
});

it('continues reading declarations after empty CSS statements', function (): void {
    $this->resources();
    $path = $this->directory . '/resources/theme.css';
    $css = file_get_contents($path);
    assert(is_string($css));
    file_put_contents($path, str_replace('--space-1:', '; --space-1:', $css));
    expect(new Inspector($this->directory)->inspect('theme'))->toBe(new Inspector(dirname(__DIR__, 2))->inspect('theme'));
});
