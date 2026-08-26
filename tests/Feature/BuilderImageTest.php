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
        $workspace,
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
        ->toBe([2, '', "Usage:\n  snippet --version\n  snippet validate\n  snippet build\n"]);
})->with([
    'no command' => [[]],
    'preview' => [['preview']],
    'draft creation' => [['new', 'page', 'about']],
    'extra argument' => [['build', 'extra']],
]);
