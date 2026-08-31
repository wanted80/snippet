#!/usr/bin/env php
<?php

declare(strict_types=1);

use Snippet\Application;
use Snippet\Cli\ErrorReporter;
use Snippet\Scaffolding\WorkspaceInitializer;

const USAGE = "Usage:\n  snippet --version\n  snippet init\n  snippet validate\n  snippet build\n  snippet new page <slug>\n  snippet new article <slug> [--date=YYYY-MM-DD]\n";

/** @var list<string> $arguments */
$arguments = $_SERVER['argv'];
$configuredEngineRoot = getenv('SNIPPET_ENGINE_ROOT');
$engineRoot = $configuredEngineRoot === false ? '/app' : $configuredEngineRoot;
$configuredWorkspace = getenv('SNIPPET_WORKSPACE');
$workspace = $configuredWorkspace === false ? '/workspace' : $configuredWorkspace;

require $engineRoot . '/vendor/autoload.php';

$stdout = new SplFileObject('php://stdout', 'w');
$stderr = new SplFileObject('php://stderr', 'w');
$errorReporter = new ErrorReporter(
    decorated: getenv('NO_COLOR') === false
        && getenv('TERM') !== 'dumb'
        && stream_isatty(STDERR),
);
if (($arguments[1] ?? null) === 'init') {
    if (count($arguments) !== 2) {
        $errorReporter->usageError($stderr, "Command 'init' does not accept arguments.", USAGE);
        exit(2);
    }

    try {
        $result = new WorkspaceInitializer($engineRoot, $workspace)->initialize();
    } catch (RuntimeException $runtimeException) {
        $errorReporter->failure(
            $stderr,
            'Workspace initialization',
            $runtimeException->getMessage(),
            $workspace,
        );
        exit(1);
    }

    if ($result['created'] === []) {
        fwrite(STDOUT, "Snippet workspace is already initialized.\nNo files were changed.\n");
        exit(0);
    }

    fwrite(STDOUT, "Initializing Snippet workspace.\n\n");
    foreach ($result['created'] as $file) {
        fwrite(STDOUT, "Created: {$file}\n");
    }
    foreach ($result['skipped'] as $file) {
        fwrite(STDOUT, "Skipped: {$file}\n");
    }
    fwrite(STDOUT, "\nWorkspace initialized.\nExisting files were not overwritten.\n");
    exit(0);
}

exit(new Application($workspace, usage: USAGE, errorReporter: $errorReporter, previewEnabled: false)->run(
    $arguments,
    $stdout,
    $stderr,
));
