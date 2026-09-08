<?php

declare(strict_types=1);

use Snippet\Support\ApplicationVersion;

it('checks the producer status and JSON envelope before continuing the Docker smoke test', function (string $fault): void {
    $root = dirname(__DIR__, 2);
    $stub = $this->directory . '/docker.php';
    file_put_contents($stub, <<<'PHP_WRAP'
    <?php
    $entrypoint = array_search('--entrypoint', $argv, true);
    if ($entrypoint !== false) {
        $command = $argv[$entrypoint + 1];
        if ($command === 'id') {
            echo '1000';
        } elseif ($command === 'php') {
            $image = array_search('snippet-builder:test', $argv, true);
            pcntl_exec(PHP_BINARY, [
                '-d', 'memory_limit=512M', '-d', 'max_execution_time=0',
                '-d', 'date.timezone=UTC', '-d', 'default_charset=UTF-8',
                '-d', 'allow_url_fopen=Off', '-d', 'display_errors=stderr',
                '-d', 'error_reporting=' . E_ALL, '-d', 'log_errors=Off',
                '-d', 'zend.assertions=-1', ...array_slice($argv, $image + 1),
            ]);
        }
        exit(0);
    }
    if (in_array('inspect', $argv, true)) {
        file_put_contents(__DIR__ . '/continued', 'yes');
        exit(97);
    }
    if (in_array('--json', $argv, true)) {
        echo getenv('SNIPPET_TEST_JSON') . "\n";
        exit((int) getenv('SNIPPET_TEST_STATUS'));
    }
    echo "Snippet test\n";
    PHP_WRAP);
    $docker = $this->directory . '/docker';
    file_put_contents($docker, "#!/bin/sh\nexec " . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($stub) . ' "$@"' . "\n");
    chmod($docker, 0755);
    $result = ['schema' => 'snippet.agent/v1', 'snippet_version' => ApplicationVersion::CURRENT];
    if ($fault === 'error') {
        $result['error'] = ['code' => 'build.failed', 'message' => 'Failure.'];
    } elseif ($fault === 'version') {
        unset($result['snippet_version']);
    } elseif ($fault === 'schema') {
        $result['schema'] = 'unknown';
    }
    $json = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $environment = getenv();
    $process = proc_open(
        ['sh', $root . '/docker/builder/smoke.sh', 'snippet-builder:test'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $root,
        [...$environment,
            'PATH' => $this->directory . ':' . $environment['PATH'],
            'SNIPPET_TEST_JSON' => $fault === 'malformed' ? $json . ' garbage' : $json,
            'SNIPPET_TEST_STATUS' => $fault === 'exit-status' ? '1' : '0',
        ],
    );
    assert(is_resource($process));
    stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->not->toBe(0)
        ->and(is_file($this->directory . '/continued'))->toBe($fault === 'valid');
})->with(['exit-status', 'error', 'version', 'schema', 'malformed', 'valid']);
