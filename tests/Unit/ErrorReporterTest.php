<?php

declare(strict_types=1);

use Snippet\Cli\ErrorReporter;

mutates(ErrorReporter::class);

it('reports failures with concise project-relative paths', function (): void {
    $stderr = new SplFileObject('php://memory', 'w+');

    new ErrorReporter()->failure(
        $stderr,
        'Validation',
        "Content directory '/workspace/content' does not exist.",
        '/workspace',
    );

    $stderr->rewind();
    expect($stderr->fread(8192))->toBe("Validation failed: Content directory 'content' does not exist.\n");
});

it('styles labels only when decoration is enabled and escapes terminal control bytes', function (): void {
    $stderr = new SplFileObject('php://memory', 'w+');
    $reporter = new ErrorReporter(decorated: true);

    $reporter->warning($stderr, 'Build', "Unsafe café\e[31m\nvalue.");
    $reporter->error($stderr, "Unable\tto continue: \x1F~\x7F");

    $stderr->rewind();
    expect($stderr->fread(8192))->toBe(
        "\e[1;33mBuild failed:\e[0m Unsafe café\\x1B[31m\\x0Avalue.\n"
        . "\e[1;31mError:\e[0m Unable\\x09to continue: \\x1F~\\x7F\n",
    );
});

it('does not relativize messages against an empty or filesystem root', function (string $root): void {
    $stderr = new SplFileObject('php://memory', 'w+');

    new ErrorReporter()->failure($stderr, 'Build', "Unable to read '/workspace/content'.", $root);

    $stderr->rewind();
    expect($stderr->fread(8192))->toBe("Build failed: Unable to read '/workspace/content'.\n");
})->with([
    'empty root' => '',
    'filesystem root' => '/',
]);

it('separates actionable usage errors from a styled usage block', function (): void {
    $stderr = new SplFileObject('php://memory', 'w+');

    new ErrorReporter(decorated: true)->usageError(
        $stderr,
        "Unknown command 'deploy'.",
        "Usage:\n  bin/snippet validate\n",
    );

    $stderr->rewind();
    expect($stderr->fread(8192))->toBe(
        "\e[1;31mError:\e[0m Unknown command 'deploy'.\n\n"
        . "\e[1mUsage:\e[0m\n  bin/snippet validate\n",
    );
});
