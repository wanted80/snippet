<?php

declare(strict_types=1);

use Snippet\Exception\ContentException;
use Snippet\Preview\PreviewServer;
use Snippet\Tests\PublisherFaults;

function availablePreviewPort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    expect($socket)->toBeResource($errorMessage ?? 'Unable to allocate a preview port.');
    assert(is_resource($socket));
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    assert(is_string($address));
    $separator = mb_strrpos($address, ':');
    assert(is_int($separator));
    return (int) mb_substr($address, $separator + 1);
}

it('serves the initial build, watches changes, and injects live reload only in preview responses', function (): void {
    $this->content();
    $path = $this->item('post', ['title' => 'Post', 'description' => 'D'], 'Before.');
    $this->resources();
    $port = availablePreviewPort();
    $response = null;
    $version = null;
    $reload = null;
    $assetHeaders = null;
    $llmsHeaders = null;
    $afterPoll = function (int $poll, string $root) use ($path, $port, &$version, &$response, &$reload, &$assetHeaders, &$llmsHeaders): void {
        if ($poll !== 0) {
            return;
        }

        $response = file_get_contents("http://127.0.0.1:{$port}/post/");
        $version = file_get_contents("http://127.0.0.1:{$port}/.snippet-preview-version");
        $reload = file_get_contents("http://127.0.0.1:{$port}/.snippet-preview-reload.js");
        $assetHeaders = get_headers("http://127.0.0.1:{$port}/assets/site.css", true);
        $llmsHeaders = get_headers("http://127.0.0.1:{$port}/llms.txt", true);
        file_put_contents($path . '/page.md', 'After!!');
    };
    $stdout = new SplFileObject('php://memory', 'w+');
    $stderr = new SplFileObject('php://memory', 'w+');
    $preview = new PreviewServer(port: $port, pollMicroseconds: 100_000, maximumPolls: 2, afterPoll: $afterPoll);

    expect($preview->run($this->directory, $stdout, $stderr))->toBe(0);
    $stdout->rewind();
    $stderr->rewind();
    $published = file_get_contents($this->directory . '/public/post/index.html');

    if (!is_string($version)) {
        throw new LogicException('Expected the preview version response to be readable.');
    }

    if (!is_array($llmsHeaders)) {
        throw new LogicException('Expected the llms.txt response headers to be readable.');
    }
    $llmsHeaders = array_change_key_case($llmsHeaders, CASE_LOWER);

    expect($version)->toMatch('/^[a-f0-9]{16}\n$/')
        ->and($response)->toBeString()
        ->toContain('<script src="/.snippet-preview-reload.js" data-version="' . mb_trim($version) . '"></script>')
        ->and($reload)->toBeString()
        ->toContain("fetch(basePath + '/.snippet-preview-version', { cache: 'no-store' })", 'const baseline = document.currentScript?.dataset.version;', 'setTimeout(check, 500);')
        ->and($assetHeaders)->toBeArray()
        ->and($assetHeaders['Cache-Control'] ?? null)->toBe('no-store')
        ->and($llmsHeaders['content-type'] ?? null)->toBe('text/plain; charset=utf-8')
        ->and($published)->toBeString()
        ->toContain('After!!')->not->toContain('.snippet-preview-version')
        ->and(file_get_contents($this->directory . '/public/.snippet-preview-version'))->toMatch('/^[a-f0-9]{16}\n$/')
        ->and($stdout->fread(8192))->toContain("Preview available at http://127.0.0.1:{$port}", 'Rebuilt site.')
        ->and($stderr->fread(8192))->toBe('');
});

it('keeps serving the last valid build when a watched edit is invalid', function (): void {
    $this->content();
    $path = $this->item('post', ['title' => 'Post', 'description' => 'D'], 'Valid.');
    $this->resources();
    $port = availablePreviewPort();
    $afterPoll = static function (int $poll, string $root) use ($path): void {
        file_put_contents($path . '/page.md', '[missing](/missing/)');
    };
    $stdout = new SplFileObject('php://memory', 'w+');
    $stderr = new SplFileObject('php://memory', 'w+');
    $preview = new PreviewServer(port: $port, pollMicroseconds: 50_000, maximumPolls: 1, afterPoll: $afterPoll);

    expect($preview->run($this->directory, $stdout, $stderr))->toBe(0);
    $stderr->rewind();

    expect(file_get_contents($this->directory . '/public/post/index.html'))->toContain('Valid.')
        ->and($stderr->fread(8192))->toContain("Build failed: Internal link target '/missing/' in '{$path}/page.md' at line 1 does not exist in the generated site.");
});

it('requests a fresh preview process when runtime source changes', function (): void {
    $this->content();
    $this->item('post', ['title' => 'Post', 'description' => 'D'], 'Published.');
    $this->resources();
    $source = $this->directory . '/src';
    mkdir($source);
    file_put_contents($source . '/Runtime.php', '<?php return "before";');
    $afterPoll = static function (int $poll, string $root) use ($source): void {
        if ($poll === 0) {
            file_put_contents($source . '/Runtime.php', '<?php return "after";');
        }
    };
    $stdout = new SplFileObject('php://memory', 'w+');
    $stderr = new SplFileObject('php://memory', 'w+');

    expect(new PreviewServer(
        port: availablePreviewPort(),
        pollMicroseconds: 50_000,
        maximumPolls: 1,
        afterPoll: $afterPoll,
    )->run($this->directory, $stdout, $stderr))->toBe(PreviewServer::RESTART_EXIT_CODE);

    $stdout->rewind();
    $stderr->rewind();
    expect($stdout->fread(8192))->toContain('Runtime source changed.')
        ->and($stderr->fread(8192))->toBe('')
        ->and(file_get_contents($this->directory . '/public/post/index.html'))->toContain('Published.');
});

it('requests a fresh preview process when the deployment path changes', function (): void {
    $this->content();
    $this->resources();
    $configPath = $this->directory . '/site/config.php';
    $afterPoll = static function () use ($configPath): void {
        $config = file_get_contents($configPath);
        assert(is_string($config));
        file_put_contents($configPath, str_replace('https://example.test', 'https://example.test/snippet', $config));
    };
    $stdout = new SplFileObject('php://memory', 'w+');

    expect(new PreviewServer(
        port: availablePreviewPort(),
        pollMicroseconds: 50_000,
        maximumPolls: 1,
        afterPoll: $afterPoll,
    )->run($this->directory, $stdout, new SplFileObject('php://memory', 'w+')))->toBe(PreviewServer::RESTART_EXIT_CODE);

    $stdout->rewind();
    expect($stdout->fread(8192))->toContain('Site deployment path changed.');
});

it('uses xxh3 for watched content fingerprints', function (): void {
    expect(new ReflectionClass(PreviewServer::class)->getConstant('FINGERPRINT_ALGORITHM'))->toBe('xxh3');
});

it('reuses hashes for unchanged non-source assets while always checking editable text', function (): void {
    $this->content();
    $this->resources();
    file_put_contents($this->directory . '/resources/large.bin', str_repeat('x', 70_000));
    $afterPoll = static function (): void {
        PublisherFaults::set('preview_large_hash_file', ['fail']);
    };

    expect(new PreviewServer(
        port: availablePreviewPort(),
        pollMicroseconds: 50_000,
        maximumPolls: 1,
        afterPoll: $afterPoll,
    )->run(
        $this->directory,
        new SplFileObject('php://memory', 'w+'),
        new SplFileObject('php://memory', 'w+'),
    ))->toBe(0);
});

it('recovers from a transient watch read failure and rebuilds the next complete edit', function (): void {
    $this->content();
    $path = $this->item('post', ['title' => 'Post', 'description' => 'D'], 'Before.');
    $this->resources();
    $afterPoll = static function (int $poll) use ($path): void {
        if ($poll === 0) {
            PublisherFaults::set('preview_stat', ['fail']);
        } elseif ($poll === 1) {
            file_put_contents($path . '/page.md', 'After.');
        }
    };
    $stdout = new SplFileObject('php://memory', 'w+');
    $stderr = new SplFileObject('php://memory', 'w+');

    expect(new PreviewServer(
        port: availablePreviewPort(),
        pollMicroseconds: 50_000,
        maximumPolls: 3,
        afterPoll: $afterPoll,
    )->run($this->directory, $stdout, $stderr))->toBe(0);

    $stdout->rewind();
    $stderr->rewind();
    expect(file_get_contents($this->directory . '/public/post/index.html'))->toContain('After.')
        ->and($stdout->fread(8192))->toContain('Rebuilt site.')
        ->and($stderr->fread(8192))->toContain('Watch failed: Unable to inspect watched file', 'Retrying.');
});

it('binds the PHP server to the host and port supplied by the CLI', function (): void {
    $this->content();
    $this->resources();
    $command = null;
    $starter = static function (array $startedCommand, string $root) use (&$command): mixed {
        $command = $startedCommand;
        return proc_open(
            [PHP_BINARY, '-r', 'sleep(10);'],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            $root,
        );
    };

    expect(new PreviewServer(maximumPolls: 0, processStarter: $starter)->run(
        $this->directory,
        new SplFileObject('php://memory', 'w+'),
        new SplFileObject('php://memory', 'w+'),
        '0.0.0.0',
        8080,
    ))->toBe(0)
        ->and($command)->toBe([
            PHP_BINARY,
            '-S',
            '0.0.0.0:8080',
            '-t',
            $this->directory . '/public',
            $this->directory . '/resources/preview-router.php',
        ]);
});

it('stops its PHP server when terminated by signal', function (int $signal, int $exitCode): void {
    $this->content();
    $this->resources();
    $port = availablePreviewPort();
    $interrupt = static function () use ($signal): void {
        $pid = getmypid();
        if ($pid === false || !posix_kill($pid, $signal)) {
            throw new RuntimeException('Unable to interrupt the preview process.');
        }
    };

    expect(new PreviewServer(
        port: $port,
        pollMicroseconds: 100_000,
        afterPoll: $interrupt,
    )->run(
        $this->directory,
        new SplFileObject('php://memory', 'w+'),
        new SplFileObject('php://memory', 'w+'),
    ))->toBe($exitCode);

    $socket = stream_socket_server("tcp://127.0.0.1:{$port}", $errorCode, $errorMessage);
    expect($socket)->toBeResource($errorMessage ?? 'The interrupted preview server is still running.');
    assert(is_resource($socket));
    fclose($socket);
})->with([
    'interrupt' => [SIGINT, 130],
    'terminate' => [SIGTERM, 143],
    'terminal closed' => [SIGHUP, 129],
]);

it('reports when the local PHP server cannot stay running', function (): void {
    $this->content();
    $this->resources();
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
    expect($socket)->toBeResource($errorMessage ?? 'Unable to occupy the preview port.');
    assert(is_resource($socket));
    $address = stream_socket_get_name($socket, false);
    assert(is_string($address));
    $separator = mb_strrpos($address, ':');
    assert(is_int($separator));
    $port = (int) mb_substr($address, $separator + 1);
    $stdout = new SplFileObject('php://memory', 'w+');
    $stderr = new SplFileObject('php://memory', 'w+');

    try {
        $status = new PreviewServer(port: $port, pollMicroseconds: 100_000, maximumPolls: 3)->run($this->directory, $stdout, $stderr);
    } finally {
        fclose($socket);
    }

    $stderr->rewind();
    expect($status)->toBe(1)
        ->and($stderr->fread(8192))->toContain('Preview server stopped unexpectedly');
});

it('does not start a server when the initial build is invalid', function (): void {
    $this->resources();
    $stdout = new SplFileObject('php://memory', 'w+');
    $stderr = new SplFileObject('php://memory', 'w+');
    new PreviewServer(port: availablePreviewPort(), maximumPolls: 0)->run($this->directory, $stdout, $stderr);
})->throws(ContentException::class, 'Content directory');

it('preserves the current publication when the preview version cannot be written', function (): void {
    $this->content();
    $this->resources();
    mkdir($this->directory . '/public');
    file_put_contents($this->directory . '/public/index.html', 'old publication');
    PublisherFaults::set('file_put_contents', ['pass', 'pass', 'pass', 'pass', 'fail']);

    expect(fn(): int => new PreviewServer(port: availablePreviewPort(), maximumPolls: 0)->run(
        $this->directory,
        new SplFileObject('php://memory', 'w+'),
        new SplFileObject('php://memory', 'w+'),
    ))->toThrow(ContentException::class, '.snippet-preview-version')
        ->and(file_get_contents($this->directory . '/public/index.html'))->toBe('old publication');
});

it('reports when the PHP preview process cannot be started', function (): void {
    $this->content();
    $this->resources();
    $starter = static fn(array $command, string $root): false => false;

    new PreviewServer(
        port: availablePreviewPort(),
        maximumPolls: 0,
        processStarter: $starter,
    )->run(
        $this->directory,
        new SplFileObject('php://memory', 'w+'),
        new SplFileObject('php://memory', 'w+'),
    );
})->throws(ContentException::class, 'Unable to start the PHP preview server');

it('tracks missing watched directories and retains the last valid build', function (): void {
    $this->content();
    $this->resources();
    $afterPoll = static function (int $poll, string $root): void {
        rename($root . '/content', $root . '/removed-content');
    };
    $stderr = new SplFileObject('php://memory', 'w+');

    expect(new PreviewServer(
        port: availablePreviewPort(),
        pollMicroseconds: 50_000,
        maximumPolls: 1,
        afterPoll: $afterPoll,
    )->run($this->directory, new SplFileObject('php://memory', 'w+'), $stderr))->toBe(0);

    $stderr->rewind();
    expect($stderr->fread(8192))->toContain('Build failed: Content directory');
});

it('rejects watched directories that cannot be inventoried', function (): void {
    $this->content();
    $this->resources();
    PublisherFaults::set('preview_scandir', ['fail']);

    new PreviewServer(port: availablePreviewPort(), maximumPolls: 0)->run(
        $this->directory,
        new SplFileObject('php://memory', 'w+'),
        new SplFileObject('php://memory', 'w+'),
    );
})->throws(ContentException::class, 'Unable to watch directory');

it('rejects watched files whose identity or contents cannot be read', function (string $operation, string $message): void {
    $this->content();
    $this->resources();
    PublisherFaults::set($operation, ['fail']);

    expect(fn(): int => new PreviewServer(port: availablePreviewPort(), maximumPolls: 0)->run(
        $this->directory,
        new SplFileObject('php://memory', 'w+'),
        new SplFileObject('php://memory', 'w+'),
    ))->toThrow(ContentException::class, $message);
})->with([
    'metadata' => ['preview_stat', 'Unable to inspect watched file'],
    'contents' => ['preview_hash_file', 'Unable to watch file'],
]);

it('fingerprints links and other filesystem entries safely', function (): void {
    $this->content();
    $this->resources();
    symlink($this->directory . '/site/config.php', $this->directory . '/resources/config-link');
    posix_mkfifo($this->directory . '/resources/events.pipe', 0600);
    PublisherFaults::set('preview_readlink', ['fail']);

    expect(new PreviewServer(port: availablePreviewPort(), maximumPolls: 0)->run(
        $this->directory,
        new SplFileObject('php://memory', 'w+'),
        new SplFileObject('php://memory', 'w+'),
    ))->toBe(0);
});

it('serves only the configured mount path and scopes redirects and live reload beneath it', function (): void {
    $this->site(['url' => 'https://example.test/snippet']);
    $this->content();
    $this->item('post', ['title' => 'Post', 'description' => 'D'], 'Mounted.');
    $this->resources();
    $port = availablePreviewPort();
    $rootHeaders = null;
    $mounted = null;
    $reload = null;
    $unmountedHeaders = null;
    $missingHeaders = null;
    $afterPoll = function () use ($port, &$rootHeaders, &$mounted, &$reload, &$unmountedHeaders, &$missingHeaders): void {
        $context = stream_context_create(['http' => ['follow_location' => 0, 'ignore_errors' => true]]);
        $rootHeaders = get_headers("http://127.0.0.1:{$port}/", false, $context);
        $mounted = file_get_contents("http://127.0.0.1:{$port}/snippet/post/");
        $reload = file_get_contents("http://127.0.0.1:{$port}/snippet/.snippet-preview-reload.js");
        $unmountedHeaders = get_headers("http://127.0.0.1:{$port}/post/", false, $context);
        $missingHeaders = get_headers("http://127.0.0.1:{$port}/snippet/missing/", false, $context);
    };
    $stdout = new SplFileObject('php://memory', 'w+');

    expect(new PreviewServer(
        port: $port,
        pollMicroseconds: 100_000,
        maximumPolls: 1,
        afterPoll: $afterPoll,
    )->run($this->directory, $stdout, new SplFileObject('php://memory', 'w+')))->toBe(0);

    $stdout->rewind();
    if (!is_array($rootHeaders)) {
        throw new LogicException('Expected root preview response headers.');
    }
    expect($rootHeaders[0] ?? null)->toContain('302')
        ->and(implode("\n", $rootHeaders))->toContain('Location: /snippet/')
        ->and($mounted)->toBeString()->toContain(
            'Mounted.',
            '<script src="/snippet/.snippet-preview-reload.js"',
            '<link rel="stylesheet" href="/snippet/assets/site.css">',
        )
        ->and($reload)->toBeString()->toContain(
            'const basePath = "/snippet";',
            "fetch(basePath + '/.snippet-preview-version'",
        )
        ->and($unmountedHeaders)->toBeArray()
        ->and($unmountedHeaders[0] ?? null)->toContain('404')
        ->and($missingHeaders)->toBeArray()
        ->and($missingHeaders[0] ?? null)->toContain('404')
        ->and($stdout->fread(8192))->toContain("Preview available at http://127.0.0.1:{$port}/snippet/");
});
