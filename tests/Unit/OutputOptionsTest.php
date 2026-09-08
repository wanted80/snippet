<?php

declare(strict_types=1);

use Snippet\Cli\OutputOptions;

mutates(OutputOptions::class);

it('preserves positional arguments and dense indexing around the JSON option', function (): void {
    $options = new OutputOptions(['snippet', 'new', 'article', 'hello', '--json', '--date=2024-02-29']);
    expect($options->json)->toBeTrue()->and($options->arguments())
        ->toBe(['snippet', 'new', 'article', 'hello', '--date=2024-02-29']);
});

it('does not interpret the executable name as an output option', function (): void {
    $options = new OutputOptions(['--json', '--version']);
    expect($options->json)->toBeFalse()->and($options->arguments())->toBe(['--json', '--version']);
    $options = new OutputOptions(['--json', '--version', '--json']);
    expect($options->json)->toBeTrue()->and($options->arguments())->toBe(['--json', '--version']);
});

it('rejects a second JSON option with the dedicated diagnostic', function (): void {
    expect(fn(): array => new OutputOptions(['snippet', '--version', '--json', '--json'])->arguments())
        ->toThrow(InvalidArgumentException::class, 'Option --json may be provided only once.');
});
