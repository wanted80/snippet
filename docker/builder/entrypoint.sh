#!/usr/bin/env php
<?php

declare(strict_types=1);

use Snippet\Application;
use Snippet\Cli\ErrorReporter;
use Snippet\Preview\PreviewServer;
use Snippet\Publishing\Publisher;
use Snippet\Scaffolding\WorkspaceInitializer;

const USAGE = "Usage:\n  snippet --version [--json]\n  snippet inspect <capabilities|theme|config|content> --json\n  snippet init [--json]\n  snippet validate [--json]\n  snippet build [--json]\n  snippet preview [--host=<host>] [--port=<port>]\n  snippet new page <slug> [--json]\n  snippet new article <slug> [--date=YYYY-MM-DD] [--json]\n";

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

do {
    $status = new Application(
        $workspace,
        publisher: new Publisher(engineRoot: $engineRoot),
        previewer: new PreviewServer(
            engineRoot: $engineRoot,
            watchRuntimeSource: false,
            errorReporter: $errorReporter,
        ),
        usage: USAGE,
        errorReporter: $errorReporter,
        previewEnabled: true,
        initializer: new WorkspaceInitializer($engineRoot, $workspace),
        engineRoot: $engineRoot,
    )->run(
        $arguments,
        $stdout,
        $stderr,
    );

    if ($status === PreviewServer::RESTART_EXIT_CODE) {
        $stdout->fwrite("Restarting preview for the updated deployment path.\n");
    }
} while ($status === PreviewServer::RESTART_EXIT_CODE);

exit($status);
