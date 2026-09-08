<?php

declare(strict_types=1);

use Snippet\Application;
use Snippet\Scaffolding\WorkspaceInitializer;
use Snippet\Support\ApplicationVersion;
use Snippet\Tests\PublisherFaults;

it('reports JSON version without publication inputs', function (): void {
    [$status, $value] = runAgentCli($this->directory, ['--version', '--json']);
    expect($status)->toBe(0)->and($value)->toBe([
        'schema' => 'snippet.agent/v1', 'snippet_version' => ApplicationVersion::CURRENT,
    ]);
});

it('dispatches engine commands without a resolvable workspace', function (array $arguments): void {
    $stdout = new SplFileObject('php://memory', 'w+');
    $stderr = new SplFileObject('php://memory', 'w+');
    /** @var list<string> $arguments */
    expect(new Application(null)->run(['snippet', ...$arguments, '--json'], $stdout, $stderr))->toBe(0);
    $stdout->rewind();
    expect(json_decode($stdout->fgets(), true, flags: JSON_THROW_ON_ERROR))->toHaveKey('schema', 'snippet.agent/v1')->not->toHaveKey('error');
    $stderr->rewind();
    expect($stderr->fread(1024))->toBe('');
})->with([
    [['--version']], [['inspect', 'capabilities']], [['inspect', 'theme']], [['inspect', 'config']], [['inspect', 'content']],
]);

it('reports unavailable workspaces through the requested output format', function (array $arguments, string $operation): void {
    foreach ([false, true] as $json) {
        $stdout = new SplFileObject('php://memory', 'w+');
        $stderr = new SplFileObject('php://memory', 'w+');
        $options = $json ? ['--json'] : [];
        /** @var list<string> $arguments */
        expect(new Application(null)->run(['snippet', ...$arguments, ...$options], $stdout, $stderr))->toBe(1);
        $stdout->rewind();
        $stderr->rewind();
        if ($json) {
            expect(json_decode($stdout->fgets(), true, flags: JSON_THROW_ON_ERROR))->toBe([
                'schema' => 'snippet.agent/v1',
                'snippet_version' => ApplicationVersion::CURRENT,
                'error' => ['code' => $operation . '.failed', 'message' => 'Unable to resolve the current workspace.'],
            ])->and($stderr->fread(1024))->toBe('');
        } else {
            expect($stdout->fread(1024))->toBe('')->and($stderr->fread(1024))->toContain('Unable to resolve the current workspace.');
        }
    }
})->with([
    [['validate'], 'validate'], [['build'], 'build'], [['new', 'page', 'hello'], 'new'],
]);

it('handles a deleted current directory through the direct executable', function (array $arguments, int $status): void {
    mkdir($this->directory . '/removed');
    $script = $this->directory . '/deleted-workspace.php';
    file_put_contents($script, <<<'PHP'
        <?php
        chdir(__DIR__ . '/removed');
        rmdir(__DIR__ . '/removed');
        $entrypoint = $argv[1];
        $_SERVER['argv'] = array_slice($argv, 1);
        require $entrypoint;
        PHP);
    /** @var list<string> $arguments */
    $process = proc_open(
        [PHP_BINARY, $script, dirname(__DIR__, 2) . '/bin/snippet', ...$arguments, '--json'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    assert(is_resource($process));
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    assert(is_string($stdout));
    expect(proc_close($process))->toBe($status)->and($stderr)->toBe('')
        ->and(json_decode($stdout, true, flags: JSON_THROW_ON_ERROR))->toHaveKey('schema', 'snippet.agent/v1');
})->with([
    [['--version'], 0], [['inspect', 'capabilities'], 0], [['validate'], 1], [['preview'], 2], [['build', '--unsupported'], 2],
]);

it('rejects invalid JSON arguments before changing the workspace', function (array $arguments): void {
    /** @var list<string> $arguments */
    [$status, $value] = runAgentCli($this->directory, $arguments);
    expect($status)->toBe(2)->and($value['error'])->toHaveKey('code', 'cli.invalid_arguments')
        ->and($this->directory . '/public')->not->toBeDirectory()
        ->and($this->directory . '/content')->not->toBeDirectory();
})->with([
    [['--json']], [['--json', 'build']], [['build', '--json', '--json']],
    [['build', '--json', '--other']], [['validate', 'extra', '--json']],
    [['preview', '--json']], [['init', '--json']], [['unknown', '--json']],
    [['new', '--json', 'page', 'hello']], [['new', 'page', '--json', 'hello']],
    [['new', 'page', 'hello', '--date=2026-08-17', '--json']],
    [['new', 'article', 'hello', '--json', '--date=bad']],
    [['new', 'article', 'hello', '--json', '--date=2026-08-17', '--date=2026-08-17']],
    [['new', 'page', 'hello', '--json', '--other']],
    [['inspect', '--json', 'theme']], [['inspect', 'site', '--json']],
    [['inspect', 'theme', '--json', 'extra']],
]);

it('creates actual incomplete files with explicit or UTC dates and preserves existing destinations', function (string $type, array $options, string $destination): void {
    $this->content();
    /** @var list<string> $options */
    $arguments = ['new', $type, 'hello', '--json', ...$options];
    [$status, $value] = runAgentCli($this->directory, $arguments);
    expect($status)->toBe(0)->and($value['incomplete'])->toBeTrue()
        ->and($value['created'])->toBe([$destination . '/' . $type . '.md', $destination . '/meta.php']);
    foreach ([$type . '.md', 'meta.php'] as $name) {
        expect($this->directory . '/' . $destination . '/' . $name)->toBeFile();
    }
    $metadata = file_get_contents($this->directory . '/' . $destination . '/meta.php');
    [$status, $value] = runAgentCli($this->directory, $arguments);
    expect($status)->toBe(1)->and($value['error'])->toHaveKey('code', 'new.failed')
        ->and(file_get_contents($this->directory . '/' . $destination . '/meta.php'))->toBe($metadata);
    [$status, $value] = runAgentCli($this->directory, ['validate', '--json']);
    expect($status)->toBe(1)->and($value['error'])->toHaveKey('code', 'validate.failed');
})->with([
    ['page', [], 'content/pages/hello'],
    ['article', [], 'content/articles/2026/08/17/hello'],
    ['article', ['--date=2024-02-29'], 'content/articles/2024/02/29/hello'],
]);

it('reports deterministic validation and finite publication counts', function (): void {
    $this->article('one', ['title' => 'One', 'description' => 'D', 'date' => '2026-01-01', 'tags' => ['PHP']]);
    [$status, $value, , $bytes] = runAgentCli($this->directory, ['validate', '--json']);
    expect($status)->toBe(0)->and($value['counts'])->toBe(['articles' => 1, 'pages' => 0, 'tags' => 1, 'assets' => 3])
        ->and(runAgentCli($this->directory, ['validate', '--json'])[3])->toBe($bytes);
    [$status, $value] = runAgentCli($this->directory, ['build', '--json']);
    expect($status)->toBe(0)->and($value['output'])->toBe('public/')
        ->and($value['counts'])->toBe(['articles' => 1, 'pages' => 0, 'tags' => 1, 'assets' => 3, 'files' => 11])
        ->and($value)->not->toHaveKeys(['warnings', 'duration']);
    file_put_contents($this->directory . '/site/config.php', 'invalid');
    $published = file_get_contents($this->directory . '/public/index.html');
    [$status, $value] = runAgentCli($this->directory, ['build', '--json']);
    expect($status)->toBe(1)->and($value['error'])->toHaveKey('code', 'build.failed')
        ->and(file_get_contents($this->directory . '/public/index.html'))->toBe($published);
});

it('reports created and skipped initialization files', function (): void {
    [$status, $value] = runAgentCli($this->directory, ['init', '--json'], true);
    expect($status)->toBe(0)->and($value['created'])->toContain('site/site.css')
        ->and($value['skipped'])->toContain('site/config.php', 'site/favicon.svg');
    [$status, $repeated] = runAgentCli($this->directory, ['init', '--json'], true);
    expect($status)->toBe(0)->and($repeated['created'])->toBe([])
        ->and($repeated['skipped'])->toContain('site/site.css', 'site/config.php');
});

it('keeps a published build successful when backup cleanup warns', function (): void {
    $this->content();
    mkdir($this->directory . '/public');
    file_put_contents($this->directory . '/public/index.html', 'Old publication.');
    PublisherFaults::set('unlink', ['fail']);
    [$status, $value] = runAgentCli($this->directory, ['build', '--json']);
    expect($status)->toBe(0)->and($value['warnings'])->toBeArray()->toHaveCount(1);
    $warnings = $value['warnings'];
    assert(is_array($warnings));
    expect($warnings[0])->toContain('The new site was published.', '.snippet-backup-', 'manually')
        ->and(file_get_contents($this->directory . '/public/index.html'))->toContain('No articles have been published yet.');
});

it('reports initialization failures without human diagnostics', function (): void {
    symlink($this->directory . '/site', $this->directory . '/content');
    [$status, $value] = runAgentCli($this->directory, ['init', '--json'], true);
    expect($status)->toBe(1)->and($value['error'])->toBe([
        'code' => 'init.failed', 'message' => "Cannot initialize 'content': the destination is a symbolic link.",
    ]);
});

it('rejects initialization options before writing files', function (): void {
    [$status, $value] = runAgentCli($this->directory, ['init', '--json', 'extra'], true);
    expect($status)->toBe(2)->and($value['error'])->toHaveKey('code', 'cli.invalid_arguments')
        ->and($this->directory . '/content')->not->toBeDirectory();
});

it('initializes through the shared dispatcher with unchanged human output', function (): void {
    $stdout = new SplFileObject('php://memory', 'w+');
    $stderr = new SplFileObject('php://memory', 'w+');
    $application = new Application($this->directory, initializer: new WorkspaceInitializer(dirname(__DIR__, 2), $this->directory));
    expect($application->run(['snippet', 'init'], $stdout, $stderr))->toBe(0);
    $stdout->rewind();
    expect($stdout->fread(65536))->toStartWith("Initializing Snippet workspace.\n\n")
        ->toContain("Skipped: site/config.php\n", "Created: site/site.css\n");
    $stdout->ftruncate(0);
    $stdout->rewind();

    expect($application->run(['snippet', 'init'], $stdout, $stderr))->toBe(0);
    $stdout->rewind();
    expect($stdout->fread(65536))->toBe("Snippet workspace is already initialized.\nNo files were changed.\n");
});

it('requires JSON for inspection and reports installed resource failures', function (): void {
    $stdout = new SplFileObject('php://memory', 'w+');
    $stderr = new SplFileObject('php://memory', 'w+');
    $application = new Application($this->directory, engineRoot: $this->directory);
    expect($application->run(['snippet', 'inspect', 'theme'], $stdout, $stderr))->toBe(2);
    $stderr->rewind();
    expect($stderr->fgets())->toContain('Inspect requires');
    $stderr->ftruncate(0);
    $stderr->rewind();

    expect($application->run(['snippet', 'inspect', 'theme', '--json'], $stdout, $stderr))->toBe(1);
    $stdout->rewind();
    $result = json_decode($stdout->fgets(), true, flags: JSON_THROW_ON_ERROR);
    expect($result)->toHaveKey('error.code', 'inspect.failed');
});

it('preserves contextual error messages in JSON including terminal control and invalid UTF-8 input', function (): void {
    [$status, $value] = runAgentCli($this->directory, ['new', 'unknown', 'hello', '--json']);
    expect($status)->toBe(2)->and($value['error'])->toHaveKey('message', "New content type 'unknown' is invalid; use 'page' or 'article'.");
    [$status, $value] = runAgentCli($this->directory, ["bad\e[31m\xFF", '--json']);
    expect($status)->toBe(2)->and($value['error'])->toHaveKey('message', "Unknown command 'bad\e[31m�'.");
});

it('inspects a read-only invalid workspace through the direct executable', function (): void {
    file_put_contents($this->directory . '/site/config.php', 'invalid configuration');
    chmod($this->directory, 0555);
    try {
        $process = proc_open(
            [dirname(__DIR__, 2) . '/bin/snippet', 'inspect', 'theme', '--json'],
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
        assert(is_string($stdout));
        expect(proc_close($process))->toBe(0)->and($stderr)->toBeEmpty()
            ->and(json_decode($stdout, true, flags: JSON_THROW_ON_ERROR))->toHaveKey('subject', 'theme');
    } finally {
        chmod($this->directory, 0755);
    }
});
