<?php

declare(strict_types=1);

use Snippet\Support\ApplicationVersion;

/** @return array{int, string, string} */
function runBuilderEntrypoint(string $workspace, string ...$arguments): array
{
    $root = dirname(__DIR__, 2);
    /** @var list<string> $command */
    $command = [PHP_BINARY, $root . '/docker/builder-entrypoint', ...$arguments];
    $process = proc_open(
        $command,
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        is_dir($workspace) ? $workspace : dirname($workspace),
        [
            'SNIPPET_ENGINE_ROOT' => $root,
            'SNIPPET_WORKSPACE' => $workspace,
        ],
    );
    expect($process)->toBeResource();
    assert(is_resource($process));
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    assert(is_string($stdout));
    assert(is_string($stderr));

    return [proc_close($process), $stdout, $stderr];
}

/** @return list<string> */
function builderScaffoldFiles(string $directory, string $prefix = ''): array
{
    $entries = scandir($directory . ($prefix === '' ? '' : '/' . $prefix));
    expect($entries)->not->toBeFalse();
    assert(is_array($entries));
    $files = [];

    foreach (array_diff($entries, ['.', '..']) as $entry) {
        $relative = $prefix === '' ? $entry : $prefix . '/' . $entry;
        if (is_dir($directory . '/' . $relative)) {
            array_push($files, ...builderScaffoldFiles($directory, $relative));

            continue;
        }

        $files[] = $relative;
    }

    return $files;
}

function removeBuilderTestSite(string $workspace): void
{
    unlink($workspace . '/site/config.php');
    unlink($workspace . '/site/favicon.svg');
    rmdir($workspace . '/site');
}

it('runs version, validation, and builds against a content-only workspace', function (): void {
    $this->content();
    $this->item('about', ['title' => 'About', 'description' => 'About this site.']);
    $this->resources();

    $build = runBuilderEntrypoint($this->directory, 'build');

    expect(runBuilderEntrypoint($this->directory, '--version'))
        ->toBe([0, 'Snippet ' . ApplicationVersion::CURRENT . "\n", ''])
        ->and(runBuilderEntrypoint($this->directory, 'validate'))
        ->toBe([0, "Valid site: 0 articles, 1 page, 0 tags, 3 assets.\n", ''])
        ->and($build[0])->toBe(0)
        ->and($build[1])->toMatch('/^Built site: 0 articles, 1 page, 0 tags, 3 assets, 9 files in \\d+ ms\\.\\n$/')
        ->and($build[2])->toBeEmpty()
        ->and(file_get_contents($this->directory . '/public/about/index.html'))
        ->toContain('<h1>About</h1>')
        ->and($this->directory . '/vendor')->not->toBeDirectory()
        ->and($this->directory . '/src')->not->toBeDirectory()
        ->and($this->directory . '/bin')->not->toBeDirectory();
});

it('initializes an empty content-only workspace from the bundled starter', function (): void {
    removeBuilderTestSite($this->directory);

    [$status, $stdout, $stderr] = runBuilderEntrypoint($this->directory, 'init');

    expect($status)->toBe(0)
        ->and($stdout)->toStartWith("Initializing Snippet workspace.\n\n")
        ->and($stdout)->toContain("Created: content/pages/about/page.md\n")
        ->and($stdout)->toContain("Created: resources/templates/layout.html\n")
        ->and($stdout)->toEndWith("\nWorkspace initialized.\nExisting files were not overwritten.\n")
        ->and($stderr)->toBeEmpty()
        ->and($this->directory . '/public')->not->toBeDirectory();

    $root = dirname(__DIR__, 2);
    foreach (['content', 'site', 'resources'] as $input) {
        foreach (builderScaffoldFiles($root . '/' . $input) as $file) {
            if ($input . '/' . $file === 'resources/preview-router.php') {
                continue;
            }
            expect(file_get_contents($this->directory . '/' . $input . '/' . $file))
                ->toBe(file_get_contents($root . '/' . $input . '/' . $file));
        }
    }
    expect($this->directory . '/resources/preview-router.php')->not->toBeFile();
});

it('adds missing scaffold files without changing existing files or public output', function (): void {
    $customConfig = "custom site configuration\n";
    file_put_contents($this->directory . '/site/config.php', $customConfig);
    mkdir($this->directory . '/public');
    file_put_contents($this->directory . '/public/index.html', 'existing publication');

    [$status, $stdout, $stderr] = runBuilderEntrypoint($this->directory, 'init');

    expect($status)->toBe(0)
        ->and($stdout)->toContain("Skipped: site/config.php\n")
        ->and($stdout)->toContain("Created: resources/templates/layout.html\n")
        ->and($stderr)->toBeEmpty()
        ->and(file_get_contents($this->directory . '/site/config.php'))->toBe($customConfig)
        ->and(file_get_contents($this->directory . '/public/index.html'))->toBe('existing publication');
});

it('is idempotent', function (): void {
    removeBuilderTestSite($this->directory);

    expect(runBuilderEntrypoint($this->directory, 'init')[0])->toBe(0)
        ->and(runBuilderEntrypoint($this->directory, 'init'))
        ->toBe([0, "Snippet workspace is already initialized.\nNo files were changed.\n", '']);
});

it('fails cleanly for an invalid workspace', function (): void {
    $workspace = $this->directory . '/workspace-file';
    file_put_contents($workspace, 'not a directory');

    expect(runBuilderEntrypoint($workspace, 'init'))
        ->toBe([1, '', "Workspace initialization failed: Workspace '{$workspace}' must be a writable non-symlink directory.\n"]);
});

it('fails cleanly for an unwritable workspace', function (): void {
    removeBuilderTestSite($this->directory);
    chmod($this->directory, 0555);

    try {
        expect(runBuilderEntrypoint($this->directory, 'init'))
            ->toBe([1, '', "Workspace initialization failed: Workspace '{$this->directory}' must be a writable non-symlink directory.\n"]);
    } finally {
        chmod($this->directory, 0755);
    }
});

it('does not follow workspace symlinks while initializing', function (): void {
    removeBuilderTestSite($this->directory);
    $outside = $this->directory . '-outside';
    mkdir($outside);
    symlink($outside, $this->directory . '/content');

    try {
        expect(runBuilderEntrypoint($this->directory, 'init'))
            ->toBe([1, '', "Workspace initialization failed: Cannot initialize 'content': the destination is a symbolic link.\n"])
            ->and(builderScaffoldFiles($outside))->toBeEmpty();
    } finally {
        rmdir($outside);
    }
});

it('reports validation failures from the mounted workspace', function (): void {
    $this->content();
    $this->resources();
    unlink($this->directory . '/resources/theme.css');

    expect(runBuilderEntrypoint($this->directory, 'validate'))
        ->toBe([1, '', "Validation failed: Publication asset 'resources/theme.css' must be a regular non-symlink file.\n"]);
});

it('creates page and article drafts in an initialized content-only workspace', function (): void {
    expect(runBuilderEntrypoint($this->directory, 'init')[0])->toBe(0)
        ->and(runBuilderEntrypoint($this->directory, 'new', 'page', 'contact'))
        ->toBe([
            0,
            "Created incomplete draft: content/pages/contact\nComplete content/pages/contact/page.md and content/pages/contact/meta.php before validating or building.\n",
            '',
        ])
        ->and(runBuilderEntrypoint($this->directory, 'new', 'article', 'first-post', '--date=2026-08-17'))
        ->toBe([
            0,
            "Created incomplete draft: content/articles/2026/08/17/first-post\nComplete content/articles/2026/08/17/first-post/article.md and content/articles/2026/08/17/first-post/meta.php before validating or building.\n",
            '',
        ])
        ->and(file_get_contents($this->directory . '/content/pages/contact/page.md'))->toBe('')
        ->and(file_get_contents($this->directory . '/content/articles/2026/08/17/first-post/article.md'))->toBe('')
        ->and(file_get_contents($this->directory . '/content/articles/2026/08/17/first-post/meta.php'))
        ->toContain("'date' => '2026-08-17'")
        ->and($this->directory . '/public')->not->toBeDirectory();
});

it('refuses draft creation when the required content structure is missing', function (): void {
    expect(runBuilderEntrypoint($this->directory, 'new', 'article', 'first-post', '--date=2026-08-17'))
        ->toBe([
            1,
            '',
            "Draft creation failed: Article collection directory 'content/articles' does not exist or is not a regular non-symlink directory.\n",
        ])
        ->and($this->directory . '/content')->not->toBeDirectory()
        ->and($this->directory . '/public')->not->toBeDirectory();
});

it('rejects commands outside the builder image contract', function (array $arguments, string $message): void {
    /** @var list<string> $arguments */
    expect(runBuilderEntrypoint($this->directory, ...$arguments))
        ->toBe([2, '', "Error: {$message}\n\nUsage:\n  snippet --version\n  snippet init\n  snippet validate\n  snippet build\n  snippet new page <slug>\n  snippet new article <slug> [--date=YYYY-MM-DD]\n"]);
})->with([
    'no command' => [[], 'A command is required.'],
    'preview' => [['preview'], "Unknown command 'preview'."],
    'extra argument' => [['build', 'extra'], "Command 'build' does not accept arguments."],
]);

it('reports new-command usage through the builder interface', function (): void {
    expect(runBuilderEntrypoint($this->directory, 'new', 'article'))
        ->toBe([
            2,
            '',
            "Error: New command requires a content type and slug.\n\nUsage:\n  snippet --version\n  snippet init\n  snippet validate\n  snippet build\n  snippet new page <slug>\n  snippet new article <slug> [--date=YYYY-MM-DD]\n",
        ]);
});

it('defines a dedicated minimal builder image and runtime configuration', function (): void {
    $root = dirname(__DIR__, 2);
    $dockerfile = file_get_contents($root . '/docker/builder.Dockerfile');
    $configuration = file_get_contents($root . '/docker/builder.ini');
    $developmentDockerfile = file_get_contents($root . '/docker/Dockerfile');

    expect($dockerfile)->toBeString()
        ->and($configuration)->toBeString()
        ->and($developmentDockerfile)->toBeString();

    assert(is_string($dockerfile));
    assert(is_string($configuration));
    assert(is_string($developmentDockerfile));

    expect($dockerfile)->toContain(
        'FROM composer:2 AS dependencies',
        'FROM php:8.5-cli-alpine AS builder',
        'docker-php-ext-install -j"$(nproc)" mbstring',
        'COPY --from=dependencies /app/vendor /app/vendor',
        'COPY src/Application.php src/Application.php',
        'COPY src/Authoring src/Authoring',
        'COPY src/Cli src/Cli',
        'COPY src/Content src/Content',
        'COPY src/Scaffolding src/Scaffolding',
        'COPY resources/theme.css resources/theme.css',
        'COPY resources/theme.js resources/theme.js',
        'COPY resources/templates resources/templates',
        'COPY docker/builder-entrypoint /usr/local/bin/snippet',
        'USER snippet',
        'WORKDIR /workspace',
        'ENTRYPOINT ["snippet"]',
    )
        ->not->toContain(
            'COPY . .',
            'src/Preview',
            'resources/preview-router.php',
            'EXPOSE',
        )
        ->and($configuration)->toBe(
            "memory_limit=512M\n"
            . "max_execution_time=0\n"
            . "date.timezone=UTC\n"
            . "default_charset=UTF-8\n"
            . "allow_url_fopen=Off\n"
            . "display_errors=stderr\n"
            . "error_reporting=E_ALL\n"
            . "log_errors=Off\n"
            . "zend.assertions=-1\n",
        )
        ->and($developmentDockerfile)->not->toContain(' AS builder');
});
