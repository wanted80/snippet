<?php

declare(strict_types=1);

use Snippet\Application;
use Snippet\Preview\Previewer;
use Snippet\Publishing\PublicationInputLoader;
use Snippet\Site\Limits;
use Snippet\Support\ApplicationVersion;

/**
 * @param list<string> $arguments
 * @return array{int, string, string}
 */
function runApplication(string $root, array $arguments, ?PublicationInputLoader $publicationInputLoader = null): array
{
    $stdout = new SplFileObject('php://memory', 'w+');
    $stderr = new SplFileObject('php://memory', 'w+');
    $status = new Application(
        $root,
        publicationInputLoader: $publicationInputLoader ?? new PublicationInputLoader(),
    )->run($arguments, $stdout, $stderr);
    $stdout->rewind();
    $stderr->rewind();
    $output = $stdout->fread(8192);
    $error = $stderr->fread(8192);
    assert(is_string($output));
    assert(is_string($error));
    return [$status, $output, $error];
}

it('reports deterministic counts on successful validation', function (): void {
    $this->content();
    $this->article('one', ['title' => 'One', 'description' => 'D', 'date' => '2026-01-01', 'tags' => []]);
    $this->item('about', ['title' => 'About', 'description' => 'D']);
    $this->resources();
    expect(runApplication($this->directory, ['bin/snippet', 'validate']))
        ->toBe([0, "Valid site and content: 2 items (1 article, 1 page).\n", '']);
});

it('reports the application version without loading publication inputs', function (): void {
    expect(runApplication($this->directory, ['bin/snippet', '--version']))
        ->toBe([0, 'Snippet ' . ApplicationVersion::CURRENT . "\n", '']);

    $root = dirname(__DIR__, 2);
    $process = proc_open(
        [$root . '/bin/snippet', '--version'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $this->directory,
    );
    expect($process)->toBeResource();
    assert(is_resource($process));
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    expect(proc_close($process))->toBe(0)
        ->and($stdout)->toBe('Snippet ' . ApplicationVersion::CURRENT . "\n")
        ->and($stderr)->toBe('');
});

it('validates publication templates with internal limits before reporting success', function (): void {
    $this->content();
    $this->resources();
    $publicationInputLoader = new PublicationInputLoader(limits: new Limits(templateBytes: 1));

    expect(runApplication($this->directory, ['bin/snippet', 'validate'], $publicationInputLoader))
        ->toBe([1, '', "Error: HTML template '{$this->directory}/resources/templates/layout.html' exceeds the configured template size limits.\n"]);
});

it('validates required publication assets before reporting success', function (string $fault, string $message): void {
    $this->content();
    $this->resources();
    $path = $this->directory . '/resources/site.css';
    if ($fault === 'missing') {
        unlink($path);
    } elseif ($fault === 'encoding') {
        file_put_contents($path, "\xFF");
    } else {
        $publicationInputLoader = new PublicationInputLoader(limits: new Limits(assetBytes: 1));
    }

    expect(runApplication($this->directory, ['bin/snippet', 'validate'], $publicationInputLoader ?? null))
        ->toBe([1, '', "Error: Publication asset '{$path}' {$message}\n"]);
})->with([
    'missing stylesheet' => ['missing', 'must be a regular non-symlink file.'],
    'invalid stylesheet encoding' => ['encoding', 'must be readable UTF-8 text.'],
    'oversized stylesheet' => ['size', 'exceeds the 1-byte asset limit.'],
]);

it('writes usage errors only to stderr', function (array $arguments): void {
    /** @var list<string> $arguments */
    expect(runApplication($this->directory, $arguments))
        ->toBe([2, '', "Usage:\n  bin/snippet --version\n  bin/snippet validate\n  bin/snippet build\n  bin/snippet preview [--host=<host>] [--port=<port>]\n  bin/snippet new page <slug>\n  bin/snippet new article <slug> [--date=YYYY-MM-DD]\n"]);
})->with([
    [[]],
    [['bin/snippet']],
    [['bin/snippet', 'validate', 'extra']],
    [['bin/snippet', '--version', 'extra']],
]);

it('rejects invalid and duplicate preview options', function (array $arguments, string $message): void {
    /** @var list<string> $arguments */
    [$status, $output, $error] = runApplication($this->directory, $arguments);

    expect($status)->toBe(2)
        ->and($output)->toBeEmpty()
        ->and($error)->toStartWith("Error: {$message}\nUsage:\n");
})->with([
    'empty host' => [['bin/snippet', 'preview', '--host='], 'Preview host must be a valid IP address or hostname.'],
    'host with scheme' => [['bin/snippet', 'preview', '--host=https://localhost'], 'Preview host must be a valid IP address or hostname.'],
    'empty port' => [['bin/snippet', 'preview', '--port='], 'Preview port must be an integer from 1 through 65535.'],
    'non-numeric port' => [['bin/snippet', 'preview', '--port=eighty'], 'Preview port must be an integer from 1 through 65535.'],
    'port too low' => [['bin/snippet', 'preview', '--port=0'], 'Preview port must be an integer from 1 through 65535.'],
    'port too high' => [['bin/snippet', 'preview', '--port=65536'], 'Preview port must be an integer from 1 through 65535.'],
    'duplicate host' => [['bin/snippet', 'preview', '--host=localhost', '--host=0.0.0.0'], 'Preview option --host may be provided only once.'],
    'duplicate port' => [['bin/snippet', 'preview', '--port=8080', '--port=8081'], 'Preview option --port may be provided only once.'],
    'unknown option' => [['bin/snippet', 'preview', '--listen=localhost'], "Unknown preview option '--listen=localhost'."],
]);

it('delegates the long-running preview command without building through the normal CLI path', function (): void {
    $previewer = new class implements Previewer {
        public bool $called = false;

        public string $root = '';

        public string $host = '';

        public int $port = 0;

        public function run(
            string $root,
            SplFileObject $stdout,
            SplFileObject $stderr,
            string $host = '127.0.0.1',
            int $port = 8080,
        ): int {
            $this->called = true;
            $this->root = $root;
            $this->host = $host;
            $this->port = $port;
            $stdout->fwrite("Preview stopped.\n");
            return 7;
        }
    };

    $stdout = new SplFileObject('php://memory', 'w+');
    $stderr = new SplFileObject('php://memory', 'w+');
    $status = new Application($this->directory, previewer: $previewer)->run(['bin/snippet', 'preview'], $stdout, $stderr);
    $stdout->rewind();

    expect($status)->toBe(7)
        ->and($previewer->called)->toBeTrue()
        ->and($previewer->root)->toBe($this->directory)
        ->and($previewer->host)->toBe('127.0.0.1')
        ->and($previewer->port)->toBe(8080)
        ->and($stdout->fgets())->toBe("Preview stopped.\n");
});

it('passes valid preview host and port overrides to the preview server', function (): void {
    $previewer = new class implements Previewer {
        /** @var array{string, int}|null */
        public ?array $address = null;

        public function run(
            string $root,
            SplFileObject $stdout,
            SplFileObject $stderr,
            string $host = '127.0.0.1',
            int $port = 8080,
        ): int {
            $this->address = [$host, $port];
            return 0;
        }
    };

    $stdout = new SplFileObject('php://memory', 'w+');
    $stderr = new SplFileObject('php://memory', 'w+');
    $status = new Application($this->directory, previewer: $previewer)->run(
        ['bin/snippet', 'preview', '--port=9000', '--host=0.0.0.0'],
        $stdout,
        $stderr,
    );

    expect($status)->toBe(0)
        ->and($previewer->address)->toBe(['0.0.0.0', 9000]);
});

it('writes actionable content errors only to stderr', function (): void {
    expect(runApplication($this->directory, ['bin/snippet', 'validate']))
        ->toBe([1, '', "Error: Content directory '{$this->directory}/content' does not exist.\n"]);
});

it('resolves the real CLI root independently from the caller working directory', function (): void {
    $root = dirname(__DIR__, 2);
    $process = proc_open(
        [$root . '/bin/snippet', 'validate'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $this->directory,
    );
    expect($process)->toBeResource();
    assert(is_resource($process));
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    expect(proc_close($process))->toBe(0)
        ->and($stdout)->toMatch('/^Valid site and content: \\d+ items? \\(\\d+ articles?, \\d+ pages?\\)\\.\\n$/')
        ->and($stderr)->toBe('');
});

it('disables the Composer timeout for the long-running preview command', function (): void {
    $contents = file_get_contents(dirname(__DIR__, 2) . '/composer.json');
    if (!is_string($contents)) {
        throw new RuntimeException('Unable to read composer.json.');
    }

    $composer = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($composer)) {
        throw new RuntimeException('composer.json must contain an object.');
    }

    $scripts = $composer['scripts'] ?? null;
    if (!is_array($scripts)) {
        throw new RuntimeException('composer.json must define scripts.');
    }

    expect($scripts['app:preview'] ?? null)->toBe([
        'Composer\\Config::disableProcessTimeout',
        'bin/snippet preview',
    ]);
});

it('describes every application Composer script', function (): void {
    $contents = file_get_contents(dirname(__DIR__, 2) . '/composer.json');
    if (!is_string($contents)) {
        throw new RuntimeException('Unable to read composer.json.');
    }

    $composer = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($composer)) {
        throw new RuntimeException('composer.json must contain an object.');
    }

    $scripts = $composer['scripts'] ?? null;
    $descriptions = $composer['scripts-descriptions'] ?? null;
    if (!is_array($scripts) || !is_array($descriptions)) {
        throw new RuntimeException('composer.json must define scripts and script descriptions.');
    }

    expect(array_keys($descriptions))->toBe(array_keys($scripts))
        ->and(array_all($descriptions, static fn(mixed $description): bool => is_string($description) && $description !== ''))
        ->toBeTrue();
});

it('declares direct PHP extension requirements in their matching dependency scopes', function (): void {
    $contents = file_get_contents(dirname(__DIR__, 2) . '/composer.json');
    if (!is_string($contents)) {
        throw new RuntimeException('Unable to read composer.json.');
    }

    $composer = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($composer)) {
        throw new RuntimeException('composer.json must contain an object.');
    }

    $require = $composer['require'] ?? null;
    $requireDev = $composer['require-dev'] ?? null;
    if (!is_array($require) || !is_array($requireDev)) {
        throw new RuntimeException('composer.json must define require and require-dev objects.');
    }

    $runtime = array_filter(
        $require,
        static fn(mixed $name): bool => is_string($name) && str_starts_with($name, 'ext-'),
        ARRAY_FILTER_USE_KEY,
    );
    $development = array_filter(
        $requireDev,
        static fn(mixed $name): bool => is_string($name) && str_starts_with($name, 'ext-'),
        ARRAY_FILTER_USE_KEY,
    );

    expect($runtime)->toBe([
        'ext-date' => '*',
        'ext-filter' => '*',
        'ext-hash' => '*',
        'ext-mbstring' => '*',
        'ext-pcre' => '*',
        'ext-random' => '*',
        'ext-tokenizer' => '*',
    ])->and($development)->toBe([
        'ext-json' => '*',
        'ext-pcntl' => '*',
        'ext-pcov' => '*',
        'ext-posix' => '*',
    ]);
});
