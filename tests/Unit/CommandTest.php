<?php

declare(strict_types=1);

use Snippet\Cli\Command;

mutates(Command::class);

it('keeps command argument policies and operation labels with the recognized command', function (string $name, bool $arguments, string $operation): void {
    $command = Command::tryFrom($name);
    expect($command)->toBeInstanceOf(Command::class);
    assert($command instanceof Command);

    expect($command->value)->toBe($name)
        ->and($command->acceptsArguments())->toBe($arguments)
        ->and($command->operation())->toBe($operation);
})->with([
    'version' => ['--version', false, 'Version reporting'],
    'validation' => ['validate', false, 'Validation'],
    'build' => ['build', false, 'Build'],
    'preview' => ['preview', true, 'Preview'],
    'authoring' => ['new', true, 'Draft creation'],
]);

it('does not normalize unknown command names into valid commands', function (string $name): void {
    expect(Command::tryFrom($name))->toBeNull();
})->with(['', 'Build', ' build', 'build ', 'version', '--help', 'init']);
