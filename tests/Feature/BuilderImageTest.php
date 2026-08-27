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

    expect(runBuilderEntrypoint($this->directory, '--version'))
        ->toBe([0, 'Snippet ' . ApplicationVersion::CURRENT . "\n", ''])
        ->and(runBuilderEntrypoint($this->directory, 'validate'))
        ->toBe([0, "Valid site and content: 1 items (0 articles, 1 page).\n", ''])
        ->and(runBuilderEntrypoint($this->directory, 'build'))
        ->toBe([0, "Built site: 1 items.\n", ''])
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
            expect(file_get_contents($this->directory . '/' . $input . '/' . $file))
                ->toBe(file_get_contents($root . '/' . $input . '/' . $file));
        }
    }
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
        ->toBe([1, '', "Error: Workspace '{$workspace}' must be a writable non-symlink directory.\n"]);
});

it('fails cleanly for an unwritable workspace', function (): void {
    removeBuilderTestSite($this->directory);
    chmod($this->directory, 0555);

    try {
        expect(runBuilderEntrypoint($this->directory, 'init'))
            ->toBe([1, '', "Error: Workspace '{$this->directory}' must be a writable non-symlink directory.\n"]);
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
            ->toBe([1, '', "Error: Cannot initialize 'content': the destination is a symbolic link.\n"])
            ->and(builderScaffoldFiles($outside))->toBeEmpty();
    } finally {
        rmdir($outside);
    }
});

it('reports validation failures from the mounted workspace', function (): void {
    $this->content();
    $this->resources();
    unlink($this->directory . '/resources/site.css');

    expect(runBuilderEntrypoint($this->directory, 'validate'))
        ->toBe([1, '', "Error: Publication asset '{$this->directory}/resources/site.css' must be a regular non-symlink file.\n"]);
});

it('rejects commands outside the builder image contract', function (array $arguments): void {
    /** @var list<string> $arguments */
    expect(runBuilderEntrypoint($this->directory, ...$arguments))
        ->toBe([2, '', "Usage:\n  snippet --version\n  snippet init\n  snippet validate\n  snippet build\n"]);
})->with([
    'no command' => [[]],
    'preview' => [['preview']],
    'draft creation' => [['new', 'page', 'about']],
    'extra argument' => [['build', 'extra']],
]);
