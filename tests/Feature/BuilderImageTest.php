<?php

declare(strict_types=1);

use Snippet\Support\ApplicationVersion;

/** @return array{int, string, string} */
function runBuilderEntrypoint(string $workspace, string ...$arguments): array
{
    $root = dirname(__DIR__, 2);
    /** @var list<string> $command */
    $command = [PHP_BINARY, $root . '/docker/builder/entrypoint.sh', ...$arguments];
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

function availableBuilderPreviewPort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    expect($socket)->toBeResource($errorMessage ?? 'Unable to allocate a builder preview port.');
    assert(is_resource($socket));
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    assert(is_string($address));
    $separator = mb_strrpos($address, ':');
    assert(is_int($separator));

    return (int) mb_substr($address, $separator + 1);
}

function waitForBuilderPreview(string $url, string $expected): string
{
    for ($attempt = 0; $attempt < 50; ++$attempt) {
        set_error_handler(static fn(): true => true);
        try {
            $response = file_get_contents($url);
        } finally {
            restore_error_handler();
        }
        if (is_string($response) && str_contains($response, $expected)) {
            return $response;
        }

        usleep(100_000);
    }

    throw new RuntimeException("Builder preview did not serve '{$expected}' at '{$url}'.");
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
        ->and($build[1])->toMatch('/^Built site: 0 articles, 1 page, 0 tags, 3 assets, 10 files in \\d+ ms\\.\\n$/')
        ->and($build[2])->toBeEmpty()
        ->and(file_get_contents($this->directory . '/public/about/index.html'))
        ->toContain('<h1>About</h1>')
        ->and($this->directory . '/vendor')->not->toBeDirectory()
        ->and($this->directory . '/src')->not->toBeDirectory()
        ->and($this->directory . '/bin')->not->toBeDirectory();
});

it('previews a content-only workspace through the engine router and restarts after a base-path change', function (): void {
    $this->content();
    $this->resources();
    unlink($this->directory . '/resources/preview-router.php');
    $port = availableBuilderPreviewPort();
    $root = dirname(__DIR__, 2);
    $process = proc_open(
        [PHP_BINARY, $root . '/docker/builder/entrypoint.sh', 'preview', "--port={$port}"],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $this->directory,
        [
            'SNIPPET_ENGINE_ROOT' => $root,
            'SNIPPET_WORKSPACE' => $this->directory,
        ],
    );
    expect($process)->toBeResource();
    assert(is_resource($process));
    $processClosed = false;

    try {
        waitForBuilderPreview("http://127.0.0.1:{$port}/", '<title>Test Site</title>');
        $this->site(['url' => 'https://example.test/snippet']);
        waitForBuilderPreview("http://127.0.0.1:{$port}/snippet/", '<title>Test Site</title>');
        proc_terminate($process, SIGTERM);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        assert(is_string($stdout));
        assert(is_string($stderr));

        $status = proc_close($process);
        $processClosed = true;
        expect($status)->toBe(143)
            ->and($stdout)->toContain(
                "Preview available at http://127.0.0.1:{$port}/.",
                'Watching publication inputs for changes.',
                'Site deployment path changed.',
                'Restarting preview for the updated deployment path.',
                "Preview available at http://127.0.0.1:{$port}/snippet/.",
            )
            ->not->toContain('runtime source')
            ->and($stderr)->not->toContain('failed');

        $socket = stream_socket_server("tcp://127.0.0.1:{$port}", $errorCode, $errorMessage);
        expect($socket)->toBeResource($errorMessage ?? 'The builder preview server is still running.');
        assert(is_resource($socket));
        fclose($socket);
    } finally {
        if (!$processClosed) {
            proc_terminate($process, SIGKILL);
            proc_close($process);
        }
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
    }
});

it('initializes an empty workspace from canonical shared inputs without demo content', function (): void {
    removeBuilderTestSite($this->directory);

    [$status, $stdout, $stderr] = runBuilderEntrypoint($this->directory, 'init');

    expect($status)->toBe(0)
        ->and($stdout)->toStartWith("Initializing Snippet workspace.\n\n")
        ->and($stdout)->not->toContain('demo/', 'article.md', 'page.md')
        ->and($stdout)->toContain("Created: resources/templates/layout.html\n")
        ->and($stdout)->toEndWith("\nWorkspace initialized.\nExisting files were not overwritten.\n")
        ->and($stderr)->toBeEmpty()
        ->and($this->directory . '/public')->not->toBeDirectory();

    $root = dirname(__DIR__, 2);
    foreach (['site', 'resources'] as $input) {
        foreach (builderScaffoldFiles($root . '/' . $input) as $file) {
            if ($input . '/' . $file === 'resources/preview-router.php') {
                continue;
            }
            expect(file_get_contents($this->directory . '/' . $input . '/' . $file))
                ->toBe(file_get_contents($root . '/' . $input . '/' . $file));
        }
    }
    expect($this->directory . '/content/articles')->toBeDirectory()
        ->and($this->directory . '/content/pages')->toBeDirectory()
        ->and(builderScaffoldFiles($this->directory . '/content'))->toBeEmpty()
        ->and($this->directory . '/resources/preview-router.php')->not->toBeFile()
        ->and(file_get_contents($this->directory . '/site/config.php'))
        ->toBe(file_get_contents($root . '/site/config.php'))
        ->not->toBe(file_get_contents($root . '/demo/site/config.php'));
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
        ->toBe([2, '', "Error: {$message}\n\nUsage:\n  snippet --version\n  snippet init\n  snippet validate\n  snippet build\n  snippet preview [--host=<host>] [--port=<port>]\n  snippet new page <slug>\n  snippet new article <slug> [--date=YYYY-MM-DD]\n"]);
})->with([
    'no command' => [[], 'A command is required.'],
    'preview option' => [['preview', '--remote'], "Unknown preview option '--remote'."],
    'extra argument' => [['build', 'extra'], "Command 'build' does not accept arguments."],
]);

it('reports new-command usage through the builder interface', function (): void {
    expect(runBuilderEntrypoint($this->directory, 'new', 'article'))
        ->toBe([
            2,
            '',
            "Error: New command requires a content type and slug.\n\nUsage:\n  snippet --version\n  snippet init\n  snippet validate\n  snippet build\n  snippet preview [--host=<host>] [--port=<port>]\n  snippet new page <slug>\n  snippet new article <slug> [--date=YYYY-MM-DD]\n",
        ]);
});

it('defines a dedicated minimal builder image and runtime configuration', function (): void {
    $root = dirname(__DIR__, 2);
    $dockerfile = file_get_contents($root . '/docker/builder/Dockerfile');
    $configuration = file_get_contents($root . '/docker/builder/php.ini');
    $developmentDockerfile = file_get_contents($root . '/docker/development/Dockerfile');

    expect($dockerfile)->toBeString()
        ->and($configuration)->toBeString()
        ->and($developmentDockerfile)->toBeString();

    assert(is_string($dockerfile));
    assert(is_string($configuration));
    assert(is_string($developmentDockerfile));

    expect($dockerfile)->toContain(
        'FROM composer:2 AS composer',
        'FROM php:8.5-cli-alpine AS dependencies',
        'FROM php:8.5-cli-alpine AS builder',
        'apk add --no-cache --virtual .snippet-build-dependencies ${PHPIZE_DEPS}',
        'docker-php-ext-install -j"$(nproc)" pcntl',
        'apk del .snippet-build-dependencies',
        'COPY --from=composer /usr/bin/composer /usr/local/bin/composer',
        'COPY --from=dependencies /app/vendor /app/vendor',
        'COPY src/Application.php src/Application.php',
        'COPY src/Authoring src/Authoring',
        'COPY src/Cli src/Cli',
        'COPY src/Content src/Content',
        'COPY src/Preview src/Preview',
        'COPY src/Scaffolding src/Scaffolding',
        'COPY resources/theme.css resources/theme.css',
        'COPY resources/theme.js resources/theme.js',
        'COPY resources/preview-router.php resources/preview-router.php',
        'COPY resources/templates resources/templates',
        'COPY docker/builder/entrypoint.sh /usr/local/bin/snippet',
        'USER snippet',
        'WORKDIR /workspace',
        'ENTRYPOINT ["snippet"]',
    )
        ->not->toContain(
            'COPY . .',
            'COPY content content',
            'COPY demo',
            '/starter',
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
