<?php

declare(strict_types=1);

use Snippet\Exception\ContentException;
use Snippet\Preview\PreviewServer;
use Snippet\Scaffolding\WorkspaceInitializer;
use Snippet\Tests\PublisherFaults;

mutates(PreviewServer::class);

it('redirects directory requests to canonical URLs while preserving the mount path and query', function (string $basePath): void {
    $this->site(['url' => 'https://example.test' . $basePath]);
    $path = $this->item('post', ['title' => 'Post', 'description' => 'D'], '[Notes](notes.txt)');
    file_put_contents($path . '/notes.txt', 'Notes.');
    mkdir($path . '/files');
    file_put_contents($path . '/files/notes.txt', 'Nested notes.');
    $this->article('article', ['title' => 'Article', 'description' => 'D', 'date' => '2026-01-01', 'tags' => ['Café']]);
    $port = availablePreviewPort();
    $afterPoll = static function () use ($port, $basePath): void {
        $origin = "http://127.0.0.1:{$port}";
        $context = stream_context_create(['http' => ['follow_location' => 0, 'ignore_errors' => true]]);
        foreach (['/post', '/articles', '/articles/article', '/tags/caf%C3%A9'] as $path) {
            $headers = get_headers($origin . $basePath . $path . '?view=all%20items', true, $context);
            assert(is_array($headers));
            expect($headers[0])->toContain('301')
                ->and($headers['Location'] ?? null)->toBe($basePath . $path . '/?view=all%20items');
        }

        foreach (['/missing', '/post/files'] as $path) {
            $headers = get_headers($origin . $basePath . $path, true, $context);
            assert(is_array($headers));
            expect($headers[0])->toContain('404')->and($headers)->not->toHaveKey('Location');
        }
        if ($basePath !== '') {
            foreach ([$basePath => '301', '/' => '302'] as $path => $status) {
                $headers = get_headers($origin . $path . '?view=all%20items', true, $context);
                assert(is_array($headers));
                expect($headers[0])->toContain($status)
                    ->and($headers['Location'] ?? null)->toBe($basePath . '/?view=all%20items');
            }
        }
        expect(file_get_contents($origin . $basePath . '/post'))->toContain('href="notes.txt"')
            ->and(file_get_contents($origin . $basePath . '/post/notes.txt'))->toBe('Notes.');
    };

    expect(new PreviewServer(
        port: $port,
        pollMicroseconds: 100_000,
        maximumPolls: 1,
        afterPoll: $afterPoll,
    )->run($this->directory, new SplFileObject('php://memory', 'w+'), new SplFileObject('php://memory', 'w+')))->toBe(0);
})->with(['', '/snippet']);

it('returns a bad request for malformed URI escapes and continues serving valid requests', function (): void {
    $this->item('post', ['title' => 'Post', 'description' => 'D'], 'Published.');
    $port = availablePreviewPort();
    $afterPoll = static function () use ($port): void {
        $origin = "http://127.0.0.1:{$port}";
        $context = stream_context_create(['http' => ['follow_location' => 0, 'ignore_errors' => true]]);
        foreach (['/post%', '/post?view=%'] as $path) {
            $headers = get_headers($origin . $path, true, $context);
            assert(is_array($headers));
            expect($headers[0])->toContain('400')->and($headers)->not->toHaveKey('Location');
        }
        foreach (['?view=%25', '?', ''] as $query) {
            $headers = get_headers($origin . '/post' . $query, true, $context);
            assert(is_array($headers));
            expect($headers[0])->toContain('301')
                ->and($headers['Location'] ?? null)->toBe('/post/' . $query);
        }
        expect(file_get_contents($origin . '/post/'))->toContain('Published.');
    };

    expect(new PreviewServer(
        port: $port,
        pollMicroseconds: 100_000,
        maximumPolls: 1,
        afterPoll: $afterPoll,
    )->run($this->directory, new SplFileObject('php://memory', 'w+'), new SplFileObject('php://memory', 'w+')))->toBe(0);
});

it('detects equal-size asset edits despite unchanged filesystem timestamps', function (string $extension, bool $immediate): void {
    $path = $this->item('post', ['title' => 'Post', 'description' => 'D']);
    $asset = $path . '/data.' . $extension;
    $before = str_repeat('Before', 12_000);
    $after = str_repeat('After!', 12_000);
    file_put_contents($asset, $before);
    PublisherFaults::record('preview_freeze_timestamps');
    $afterPoll = static function (int $poll, string $root) use ($asset, $extension, $before, $after, $immediate): void {
        if ($poll === 0) {
            file_put_contents($asset, $after);
        } elseif ($poll === 1) {
            expect(hash_file('xxh3', $root . '/public/post/data.' . $extension))->toBe(hash('xxh3', $immediate ? $after : $before));
        }
    };
    $stdout = new SplFileObject('php://memory', 'w+');
    $stderr = new SplFileObject('php://memory', 'w+');

    expect(new PreviewServer(
        port: availablePreviewPort(),
        pollMicroseconds: 1000,
        maximumPolls: $immediate ? 2 : 22,
        afterPoll: $afterPoll,
    )->run($this->directory, $stdout, $stderr))->toBe(0);

    $stdout->rewind();
    $stderr->rewind();
    $output = $stdout->fread(8192);
    assert(is_string($output));
    expect(hash_file('xxh3', $this->directory . '/public/post/data.' . $extension))->toBe(hash('xxh3', $after))
        ->and(mb_substr_count($output, 'Rebuilt site.'))->toBe(1)
        ->and($stderr->fread(8192))->toBe('')
        ->and(PublisherFaults::calls('preview_hash:' . $asset))->toBe($immediate ? 3 : 2);
})->with([
    'text' => ['txt', true],
    'vector' => ['svg', true],
    'data' => ['json', true],
    'XML' => ['xml', true],
    'binary' => ['bin', false],
]);

it('keeps preview available and reports cleanup warnings after a successful publication', function (bool $duringRebuild): void {
    $path = $this->item('post', ['title' => 'Post', 'description' => 'D'], 'Published.');
    mkdir($this->directory . '/public');
    file_put_contents($this->directory . '/public/index.html', 'Old publication.');
    if (!$duringRebuild) {
        PublisherFaults::set('unlink', ['fail']);
    }
    $afterPoll = static function () use ($path, $duringRebuild): void {
        if ($duringRebuild) {
            PublisherFaults::set('unlink', ['fail']);
            file_put_contents($path . '/page.md', 'Rebuilt.');
        }
    };
    $stdout = new SplFileObject('php://memory', 'w+');
    $stderr = new SplFileObject('php://memory', 'w+');

    expect(new PreviewServer(
        port: availablePreviewPort(),
        pollMicroseconds: 1000,
        maximumPolls: 1,
        afterPoll: $afterPoll,
    )->run($this->directory, $stdout, $stderr))->toBe(0);

    $stdout->rewind();
    $stderr->rewind();
    expect($stdout->fread(8192))->toContain($duringRebuild ? 'Rebuilt site.' : 'Preview available')
        ->and($stderr->fread(8192))->toStartWith('Publication cleanup failed:')->toContain('The new site was published.')
        ->and(file_get_contents($this->directory . '/public/post/index.html'))->toContain($duringRebuild ? 'Rebuilt.' : 'Published.');
})->with([false, true]);

it('serves published downloads as static bytes without executing them', function (): void {
    $path = $this->item('post', ['title' => 'Post', 'description' => 'D'], '[notes](notes.txt) [guide](guide.pdf)');
    $this->resources();
    $files = [
        'notes.txt' => ['Notes.', 'text/plain; charset=utf-8'],
        'guide.pdf' => ['%PDF-1.4', 'application/pdf'],
        'data.json' => ['{"ok":true}', 'application/json'],
        'animation.gif' => ['GIF89a', 'image/gif'],
        'photo.avif' => ['avif', 'image/avif'],
        'icon.ico' => ['icon', 'image/vnd.microsoft.icon'],
        'type.woff' => ['font', 'font/woff'],
        'example.php' => ['<?php echo "executed";', 'application/octet-stream'],
        'a%20b.bin' => ["\x00\xFF", 'application/octet-stream'],
    ];
    foreach ($files as $filename => [$contents]) {
        file_put_contents($path . '/' . $filename, $contents);
    }
    $port = availablePreviewPort();
    $afterPoll = static function () use ($files, $port): void {
        foreach ($files as $filename => [$contents, $contentType]) {
            $url = "http://127.0.0.1:{$port}/post/" . rawurlencode($filename);
            $headers = get_headers($url, true);
            expect($headers)->toBeArray();
            assert(is_array($headers));
            $headers = array_change_key_case($headers, CASE_LOWER);

            expect($headers[0])->toContain('200 OK')
                ->and($headers['content-type'])->toBe($contentType)
                ->and($headers['x-content-type-options'])->toBe('nosniff')
                ->and($headers['content-disposition'] ?? null)->toBe($contentType === 'application/octet-stream' ? 'attachment' : null)
                ->and(file_get_contents($url))->toBe($contents);
        }
    };

    expect(new PreviewServer(
        port: $port,
        pollMicroseconds: 100_000,
        maximumPolls: 1,
        afterPoll: $afterPoll,
        engineRoot: $this->directory,
    )->run($this->directory, new SplFileObject('php://memory', 'w+'), new SplFileObject('php://memory', 'w+')))->toBe(0);
});

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
        $stylesheets = glob($root . '/public/assets/theme.*.css');
        assert(is_array($stylesheets) && count($stylesheets) === 1);
        $assetHeaders = get_headers("http://127.0.0.1:{$port}/assets/" . basename($stylesheets[0]), true);
        $llmsHeaders = get_headers("http://127.0.0.1:{$port}/llms.txt", true);
        file_put_contents($path . '/page.md', 'After!!');
    };
    $stdout = new SplFileObject('php://memory', 'w+');
    $stderr = new SplFileObject('php://memory', 'w+');
    $preview = new PreviewServer(port: $port, pollMicroseconds: 100_000, maximumPolls: 2, afterPoll: $afterPoll, engineRoot: $this->directory);

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
    $preview = new PreviewServer(port: $port, pollMicroseconds: 50_000, maximumPolls: 1, afterPoll: $afterPoll, engineRoot: $this->directory);

    expect($preview->run($this->directory, $stdout, $stderr))->toBe(0);
    $stderr->rewind();

    expect(file_get_contents($this->directory . '/public/post/index.html'))->toContain('Valid.')
        ->and($stderr->fread(8192))->toBe("Build failed: Internal link target '/missing/' in 'content/pages/post/page.md' at line 1 does not exist in the generated site. Keeping the last valid site.\n");
});

it('requests a fresh preview process when runtime source changes', function (): void {
    $this->content();
    $this->item('post', ['title' => 'Post', 'description' => 'D'], 'Published.');
    $this->resources();
    $source = $this->directory . '/src';
    mkdir($source);
    file_put_contents($source . '/Runtime.php', '<?php return "before";');
    $afterPoll = static function (int $poll, string $root) use ($source): void {
        if ($poll === 1) {
            file_put_contents($source . '/Runtime.php', '<?php return "after";');
        }
    };
    $stdout = new SplFileObject('php://memory', 'w+');
    $stderr = new SplFileObject('php://memory', 'w+');

    expect(new PreviewServer(
        port: availablePreviewPort(),
        pollMicroseconds: 50_000,
        maximumPolls: 2,
        afterPoll: $afterPoll,
        engineRoot: $this->directory,
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
        engineRoot: $this->directory,
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
        engineRoot: $this->directory,
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
        engineRoot: $this->directory,
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

    expect(new PreviewServer(maximumPolls: 0, processStarter: $starter, engineRoot: $this->directory)->run(
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

it('uses an injected engine router for a content-only publication without watching runtime source', function (): void {
    $this->content();
    $this->resources();
    unlink($this->directory . '/resources/preview-router.php');
    $router = dirname(__DIR__, 2) . '/resources/preview-router.php';
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
    $stdout = new SplFileObject('php://memory', 'w+');

    expect(new PreviewServer(
        maximumPolls: 0,
        processStarter: $starter,
        routerPath: $router,
        watchRuntimeSource: false,
        engineRoot: $this->directory,
    )->run(
        $this->directory,
        $stdout,
        new SplFileObject('php://memory', 'w+'),
    ))->toBe(0)
        ->and($this->directory . '/bin')->not->toBeDirectory()
        ->and($this->directory . '/src')->not->toBeDirectory()
        ->and($command)->toBe([
            PHP_BINARY,
            '-S',
            '127.0.0.1:8080',
            '-t',
            $this->directory . '/public',
            $router,
        ]);

    $stdout->rewind();
    expect($stdout->fread(8192))->toContain('Watching publication inputs for changes.')
        ->not->toContain('runtime source');
});

it('does not request a restart for runtime changes when runtime watching is disabled', function (): void {
    $this->content();
    $this->resources();
    $source = $this->directory . '/src';
    mkdir($source);
    file_put_contents($source . '/Runtime.php', '<?php return "before";');
    $afterPoll = static function () use ($source): void {
        file_put_contents($source . '/Runtime.php', '<?php return "after";');
    };

    expect(new PreviewServer(
        port: availablePreviewPort(),
        pollMicroseconds: 50_000,
        maximumPolls: 1,
        afterPoll: $afterPoll,
        watchRuntimeSource: false,
        engineRoot: $this->directory,
    )->run(
        $this->directory,
        new SplFileObject('php://memory', 'w+'),
        new SplFileObject('php://memory', 'w+'),
    ))->toBe(0);
});

it('rejects an injected preview router that is not a readable regular non-symlink file', function (string $kind): void {
    $this->content();
    $this->resources();
    $router = $this->directory . '/engine-router.php';
    match ($kind) {
        'directory' => mkdir($router),
        'symlink' => symlink(dirname(__DIR__, 2) . '/resources/preview-router.php', $router),
        'unreadable' => copy(dirname(__DIR__, 2) . '/resources/preview-router.php', $router) && chmod($router, 0000),
        'missing' => null,
        default => throw new LogicException("Unexpected router fixture '{$kind}'."),
    };

    expect(fn(): int => new PreviewServer(maximumPolls: 0, routerPath: $router, engineRoot: $this->directory)->run(
        $this->directory,
        new SplFileObject('php://memory', 'w+'),
        new SplFileObject('php://memory', 'w+'),
    ))->toThrow(
        ContentException::class,
        "Preview router '{$router}' must be a readable regular non-symlink file.",
    );
})->with(['missing', 'directory', 'symlink', 'unreadable']);

it('connects the built-in server to the three standard streams', function (): void {
    $this->content();
    $this->resources();

    expect(new PreviewServer(port: availablePreviewPort(), maximumPolls: 0, engineRoot: $this->directory)->run(
        $this->directory,
        new SplFileObject('php://memory', 'w+'),
        new SplFileObject('php://memory', 'w+'),
    ))->toBe(0)
        ->and(PublisherFaults::calls('preview_descriptor_0'))->toBe(1)
        ->and(PublisherFaults::calls('preview_descriptor_1'))->toBe(1)
        ->and(PublisherFaults::calls('preview_descriptor_2'))->toBe(1);
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
        engineRoot: $this->directory,
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
        $status = new PreviewServer(port: $port, pollMicroseconds: 100_000, maximumPolls: 3, engineRoot: $this->directory)->run($this->directory, $stdout, $stderr);
    } finally {
        fclose($socket);
    }

    $stderr->rewind();
    expect($status)->toBe(1)
        ->and($stderr->fread(8192))->toBe("Preview server failed: The local PHP server stopped unexpectedly.\n");
});

it('does not start a server when the initial build is invalid', function (): void {
    $this->resources();
    $stdout = new SplFileObject('php://memory', 'w+');
    $stderr = new SplFileObject('php://memory', 'w+');
    new PreviewServer(port: availablePreviewPort(), maximumPolls: 0, engineRoot: $this->directory)->run($this->directory, $stdout, $stderr);
})->throws(ContentException::class, 'Content directory');

it('preserves the current publication when the preview version cannot be written', function (): void {
    $this->content();
    $this->resources();
    mkdir($this->directory . '/public');
    file_put_contents($this->directory . '/public/index.html', 'old publication');
    PublisherFaults::set('file_put_contents', ['pass', 'pass', 'pass', 'pass', 'pass', 'fail']);

    expect(fn(): int => new PreviewServer(port: availablePreviewPort(), maximumPolls: 0, engineRoot: $this->directory)->run(
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
        engineRoot: $this->directory,
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
        engineRoot: $this->directory,
    )->run($this->directory, new SplFileObject('php://memory', 'w+'), $stderr))->toBe(0);

    $stderr->rewind();
    expect($stderr->fread(8192))->toContain('Build failed: Content directory');
});

it('rejects watched directories that cannot be inventoried', function (): void {
    $this->content();
    $this->resources();
    PublisherFaults::set('preview_scandir', ['fail']);

    new PreviewServer(port: availablePreviewPort(), maximumPolls: 0, engineRoot: $this->directory)->run(
        $this->directory,
        new SplFileObject('php://memory', 'w+'),
        new SplFileObject('php://memory', 'w+'),
    );
})->throws(ContentException::class, 'Unable to watch directory');

it('rejects watched files whose identity or contents cannot be read', function (string $operation, string $message): void {
    $this->content();
    $this->resources();
    PublisherFaults::set($operation, ['fail']);

    expect(fn(): int => new PreviewServer(port: availablePreviewPort(), maximumPolls: 0, engineRoot: $this->directory)->run(
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
    $target = $this->directory . '/site/config.php';
    $directory = $this->directory . '/inventory';
    mkdir($directory);
    symlink($target, $directory . '/config-link');
    posix_mkfifo($directory . '/events.pipe', 0600);
    $method = new ReflectionMethod(PreviewServer::class, 'inventory');
    $watchedFiles = [];
    $arguments = [$directory, 'inventory', &$watchedFiles];
    $inventory = $method->invokeArgs(new PreviewServer(), $arguments);
    assert($inventory instanceof Generator);
    $records = iterator_to_array($inventory, false);

    expect($records)->toBe([
        "inventory/config-link:link:{$target}",
        'inventory/events.pipe:other',
    ])->and($watchedFiles)->toBeEmpty();

    PublisherFaults::set('preview_readlink', ['fail']);
    $watchedFiles = [];
    $arguments = [$directory, 'inventory', &$watchedFiles];
    $inventory = $method->invokeArgs(new PreviewServer(), $arguments);
    assert($inventory instanceof Generator);

    expect(iterator_to_array($inventory, false))->toBe([
        'inventory/config-link:link:',
        'inventory/events.pipe:other',
    ])->and($watchedFiles)->toBeEmpty();
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
    $missing = null;
    $afterPoll = function () use ($port, &$rootHeaders, &$mounted, &$reload, &$unmountedHeaders, &$missingHeaders, &$missing): void {
        $context = stream_context_create(['http' => ['follow_location' => 0, 'ignore_errors' => true]]);
        $rootHeaders = get_headers("http://127.0.0.1:{$port}/", false, $context);
        $mounted = file_get_contents("http://127.0.0.1:{$port}/snippet/post/");
        $reload = file_get_contents("http://127.0.0.1:{$port}/snippet/.snippet-preview-reload.js");
        $unmountedHeaders = get_headers("http://127.0.0.1:{$port}/post/", false, $context);
        $missingHeaders = get_headers("http://127.0.0.1:{$port}/snippet/missing/", false, $context);
        $missing = file_get_contents("http://127.0.0.1:{$port}/snippet/missing/", false, $context);
    };
    $stdout = new SplFileObject('php://memory', 'w+');

    expect(new PreviewServer(
        port: $port,
        pollMicroseconds: 100_000,
        maximumPolls: 1,
        afterPoll: $afterPoll,
        engineRoot: $this->directory,
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
        )
        ->toMatch('~<link rel="stylesheet" href="/snippet/assets/theme\.[0-9a-f]{16}\.css">~')
        ->and($reload)->toBeString()->toContain(
            'const basePath = "/snippet";',
            "fetch(basePath + '/.snippet-preview-version'",
        )
        ->and($unmountedHeaders)->toBeArray()
        ->and($unmountedHeaders[0] ?? null)->toContain('404')
        ->and($missingHeaders)->toBeArray()
        ->and($missingHeaders[0] ?? null)->toContain('404')
        ->and($missing)->toBeString()->toContain(
            '<h1 id="not-found-title">Page not found</h1>',
            '<a class="button-link" href="/snippet/">Return home',
            '<script src="/snippet/.snippet-preview-reload.js"',
        )
        ->toMatch('~<link rel="stylesheet" href="/snippet/assets/theme\.[0-9a-f]{16}\.css">~')
        ->and($stdout->fread(8192))->toContain("Preview available at http://127.0.0.1:{$port}/snippet/");
});

it('watches the installed theme separately from the author workspace and preserves the last valid preview', function (): void {
    $this->resources();
    $workspace = $this->directory . '/publication';
    mkdir($workspace);
    $initialized = new WorkspaceInitializer($this->directory, $workspace)->initialize();
    expect($initialized['created'])->not->toBeEmpty();
    $layoutPath = $this->directory . '/resources/templates/layout.html';
    $layout = file_get_contents($layoutPath);
    assert(is_string($layout));
    $stdout = new SplFileObject('php://memory', 'w+');
    $stderr = new SplFileObject('php://memory', 'w+');
    $afterPoll = static function (int $poll, string $root) use ($layoutPath, $layout): void {
        if ($poll === 0) {
            file_put_contents($root . '/site/site.css', '@layer overrides { :root { --measure-prose: 42rem; } }');
        } elseif ($poll === 1) {
            file_put_contents($layoutPath, str_replace('<body>', '<body class="updated-theme">', $layout));
        } else {
            file_put_contents($layoutPath, '{{invalid}}');
        }
    };

    expect(new PreviewServer(
        port: availablePreviewPort(),
        pollMicroseconds: 1000,
        maximumPolls: 3,
        afterPoll: $afterPoll,
        engineRoot: $this->directory,
    )->run($workspace, $stdout, $stderr))->toBe(0);

    $stdout->rewind();
    $stderr->rewind();
    $output = $stdout->fread(8192);
    assert(is_string($output));
    expect(mb_substr_count($output, 'Rebuilt site.'))->toBe(2)
        ->and($stderr->fread(8192))->toContain('Keeping the last valid site.')
        ->and(file_get_contents($workspace . '/public/index.html'))->toContain('<body class="updated-theme">')
        ->and($workspace . '/resources')->not->toBeDirectory();
});
