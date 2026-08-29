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
function runApplication(string $root, array $arguments, ?PublicationInputLoader $publicationInputLoader = null, ?Closure $nanoseconds = null): array
{
    $stdout = new SplFileObject('php://memory', 'w+');
    $stderr = new SplFileObject('php://memory', 'w+');
    $status = new Application(
        $root,
        publicationInputLoader: $publicationInputLoader ?? new PublicationInputLoader(),
        nanoseconds: $nanoseconds,
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
        ->toBe([0, "Valid site: 1 article, 1 page, 0 tags, 3 assets.\n", '']);
});

it('reports successful publication counts and rounded full-command duration', function (): void {
    $article = $this->article('one', ['title' => 'One', 'description' => 'D', 'date' => '2026-01-01', 'tags' => ['One', 'Two']]);
    file_put_contents($article . '/notes.txt', 'notes');
    $this->item('about', ['title' => 'About', 'description' => 'D']);
    $this->resources();
    $readings = [1_000_000, 14_400_000];

    expect(runApplication(
        $this->directory,
        ['bin/snippet', 'build'],
        nanoseconds: static function () use (&$readings): int {
            $reading = array_shift($readings);
            assert(is_int($reading));

            return $reading;
        },
    ))->toBe([0, "Built site: 1 article, 1 page, 2 tags, 4 assets, 13 files in 13 ms.\n", '']);
});

it('uses correct plurals in validation and build summaries', function (): void {
    $this->content();
    $this->resources();
    $readings = [0, 600_000];

    expect(runApplication($this->directory, ['bin/snippet', 'validate']))
        ->toBe([0, "Valid site: 0 articles, 0 pages, 0 tags, 3 assets.\n", ''])
        ->and(runApplication(
            $this->directory,
            ['bin/snippet', 'build'],
            nanoseconds: static function () use (&$readings): int {
                $reading = array_shift($readings);
                assert(is_int($reading));

                return $reading;
            },
        ))->toBe([0, "Built site: 0 articles, 0 pages, 0 tags, 3 assets, 8 files in 1 ms.\n", '']);
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
        ->toBe([1, '', "Validation failed: HTML template 'resources/templates/layout.html' exceeds the configured template size limits.\n"]);
});

it('validates required publication assets before reporting success', function (string $fault, string $message): void {
    $this->content();
    $this->resources();
    $path = $this->directory . '/resources/theme.css';
    if ($fault === 'missing') {
        unlink($path);
    } elseif ($fault === 'encoding') {
        file_put_contents($path, "\xFF");
    } else {
        $publicationInputLoader = new PublicationInputLoader(limits: new Limits(assetBytes: 1));
    }

    expect(runApplication($this->directory, ['bin/snippet', 'validate'], $publicationInputLoader ?? null))
        ->toBe([1, '', "Validation failed: Publication asset 'resources/theme.css' {$message}\n"]);
})->with([
    'missing stylesheet' => ['missing', 'must be a regular non-symlink file.'],
    'invalid stylesheet encoding' => ['encoding', 'must be readable UTF-8 text.'],
    'oversized stylesheet' => ['size', 'exceeds the 1-byte asset limit.'],
]);

it('validates the required favicon asset', function (string $fault, string $message): void {
    $this->content();
    $this->resources();
    $path = $this->directory . '/site/favicon.svg';
    $expectedMessage = $message;
    if ($fault === 'missing') {
        unlink($path);
    } elseif ($fault === 'encoding') {
        file_put_contents($path, "\xFF");
    } else {
        $size = filesize($path);
        assert(is_int($size));
        file_put_contents($this->directory . '/resources/theme.css', 'x');
        file_put_contents($this->directory . '/resources/theme.js', 'x');
        $publicationInputLoader = new PublicationInputLoader(limits: new Limits(assetBytes: $size - 1));
        $expectedMessage = 'exceeds the ' . ($size - 1) . '-byte asset limit.';
    }

    expect(runApplication($this->directory, ['bin/snippet', 'validate'], $publicationInputLoader ?? null))
        ->toBe([1, '', "Validation failed: Publication asset 'site/favicon.svg' {$expectedMessage}\n"]);
})->with([
    'missing favicon' => ['missing', 'must be a regular non-symlink file.'],
    'invalid favicon encoding' => ['encoding', 'must be readable UTF-8 text.'],
    'oversized favicon' => ['size', 'exceeds the 1-byte asset limit.'],
]);

it('writes actionable usage errors only to stderr', function (array $arguments, string $message): void {
    /** @var list<string> $arguments */
    expect(runApplication($this->directory, $arguments))
        ->toBe([2, '', "Error: {$message}\n\nUsage:\n  bin/snippet --version\n  bin/snippet validate\n  bin/snippet build\n  bin/snippet preview [--host=<host>] [--port=<port>]\n  bin/snippet new page <slug>\n  bin/snippet new article <slug> [--date=YYYY-MM-DD]\n"]);
})->with([
    'missing executable and command' => [[], 'A command is required.'],
    'missing command' => [['bin/snippet'], 'A command is required.'],
    'unknown command' => [['bin/snippet', 'deploy'], "Unknown command 'deploy'."],
    'terminal control in command' => [['bin/snippet', "\e[31mdeploy"], "Unknown command '\\x1B[31mdeploy'."],
    'validate argument' => [['bin/snippet', 'validate', 'extra'], "Command 'validate' does not accept arguments."],
    'build argument' => [['bin/snippet', 'build', 'extra'], "Command 'build' does not accept arguments."],
    'version argument' => [['bin/snippet', '--version', 'extra'], "Command '--version' does not accept arguments."],
]);

it('rejects invalid and duplicate preview options', function (array $arguments, string $message): void {
    /** @var list<string> $arguments */
    [$status, $output, $error] = runApplication($this->directory, $arguments);

    expect($status)->toBe(2)
        ->and($output)->toBeEmpty()
        ->and($error)->toStartWith("Error: {$message}\n\nUsage:\n");
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
    'unexpected argument' => [['bin/snippet', 'preview', 'extra'], "Unexpected preview argument 'extra'."],
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

it('identifies the failed operation in actionable content errors', function (string $command, string $operation): void {
    expect(runApplication($this->directory, ['bin/snippet', $command]))
        ->toBe([1, '', "{$operation} failed: Content directory 'content' does not exist.\n"]);
})->with([
    'validation' => ['validate', 'Validation'],
    'build' => ['build', 'Build'],
    'preview' => ['preview', 'Preview'],
]);

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
        ->and($stdout)->toMatch('/^Valid site: \\d+ articles?, \\d+ pages?, \\d+ tags?, \\d+ assets?\\.\\n$/')
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
