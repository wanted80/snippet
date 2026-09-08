<?php

declare(strict_types=1);

use Snippet\Application;
use Snippet\Authoring\DraftCreator;
use Snippet\Scaffolding\WorkspaceInitializer;
use Snippet\Support\ApplicationVersion;

/**
 * @param list<string> $arguments
 * @return array{int, array<string, mixed>, string, string}
 */
function runAgentCli(string $root, array $arguments, bool $docker = false): array
{
    $stdout = new SplFileObject('php://memory', 'w+');
    $stderr = new SplFileObject('php://memory', 'w+');
    $status = new Application(
        $root,
        draftCreator: new DraftCreator(new DateTimeImmutable('2026-08-18T00:30:00+02:00')),
        initializer: $docker ? new WorkspaceInitializer(dirname(__DIR__), $root) : null,
    )->run(['snippet', ...$arguments], $stdout, $stderr);
    $stdout->rewind();
    $stderr->rewind();
    $bytes = $stdout->fread(65536);
    $error = $stderr->fread(65536);
    assert(is_string($bytes) && is_string($error));
    $value = json_decode($bytes, true, flags: JSON_THROW_ON_ERROR);
    assert(is_array($value));
    /** @var array<string, mixed> $value */
    expect($value['schema'])->toBe('snippet.agent/v1')
        ->and($value['snippet_version'])->toBe(ApplicationVersion::CURRENT)
        ->and(mb_substr_count($bytes, "\n"))->toBe(1)
        ->and($bytes)->toEndWith("\n")
        ->and($error)->toBeEmpty();
    return [$status, $value, $error, $bytes];
}
