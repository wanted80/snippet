<?php

declare(strict_types=1);

namespace Snippet\Preview;

use Closure;
use Generator;
use Snippet\Cli\ErrorReporter;
use Snippet\Exception\ContentException;
use Snippet\Publishing\PublicationInputLoader;
use Snippet\Publishing\Publisher;
use Snippet\Site\Config;
use SplFileObject;

/** Builds, serves, watches, and live-reloads the local development site. */
final class PreviewServer implements Previewer
{
    public const int RESTART_EXIT_CODE = 75;

    private const string FINGERPRINT_ALGORITHM = 'xxh3';

    private const array PUBLICATION_WATCH_PATHS = ['content', 'resources', 'site'];

    private const array RUNTIME_WATCH_PATHS = ['bin', 'src'];

    /** @var array<string, array{signature: string, hash: string}> */
    private array $watchedFiles = [];

    public function __construct(
        private readonly Publisher $publisher = new Publisher(),
        private readonly PublicationInputLoader $publicationInputLoader = new PublicationInputLoader(),
        private readonly ?string $host = null,
        private readonly ?int $port = null,
        private readonly int $pollMicroseconds = 250_000,
        private readonly ?int $maximumPolls = null,
        private readonly ?Closure $afterPoll = null,
        private readonly ?Closure $processStarter = null,
        private readonly ErrorReporter $errorReporter = new ErrorReporter(),
    ) {}

    public function run(
        string $root,
        SplFileObject $stdout,
        SplFileObject $stderr,
        string $host = '127.0.0.1',
        int $port = 8080,
    ): int {
        $host = $this->host ?? $host;
        $port = $this->port ?? $port;
        $config = $this->rebuild($root);
        $fingerprints = $this->fingerprints($root);
        $terminationSignal = null;
        $restoreSignalHandling = null;
        if (
            function_exists('pcntl_async_signals')
            && function_exists('pcntl_signal')
            && function_exists('pcntl_signal_get_handler')
        ) {
            $asyncSignalsWereEnabled = pcntl_async_signals(true);
            $signalHandlers = [];
            foreach ([SIGINT, SIGTERM, SIGHUP] as $signal) {
                $signalHandlers[$signal] = pcntl_signal_get_handler($signal);
                pcntl_signal($signal, static function (int $receivedSignal) use (&$terminationSignal): void {
                    $terminationSignal = $receivedSignal;
                });
            }

            $restoreSignalHandling = static function () use ($asyncSignalsWereEnabled, $signalHandlers): void {
                foreach ($signalHandlers as $signal => $signalHandler) {
                    pcntl_signal($signal, $signalHandler);
                }

                pcntl_async_signals($asyncSignalsWereEnabled);
            };
        }

        $process = null;
        $formattedHost = str_contains($host, ':') ? "[{$host}]" : $host;
        $address = sprintf('http://%s:%d%s/', $formattedHost, $port, $config->basePath);
        $poll = 0;
        $watchError = null;
        try {
            $process = $this->start($root, $formattedHost, $port, $config->basePath);
            $stdout->fwrite("Preview available at {$address}. Press Ctrl+C to stop.\n");
            $stdout->fwrite("Watching publication inputs and runtime source for changes.\n");

            while ($terminationSignal === null && ($this->maximumPolls === null || $poll < $this->maximumPolls)) {
                usleep($this->pollMicroseconds);
                if ($this->afterPoll instanceof Closure) {
                    ($this->afterPoll)($poll, $root);
                }

                ++$poll;
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $this->errorReporter->failure(
                        $stderr,
                        'Preview server',
                        'The local PHP server stopped unexpectedly.',
                        $root,
                    );
                    return 1;
                }

                try {
                    $current = $this->fingerprints($root);
                    $watchError = null;
                } catch (ContentException $contentException) {
                    $message = $contentException->getMessage();
                    if ($message !== $watchError) {
                        $this->errorReporter->warning($stderr, 'Watch', "{$message} Retrying.", $root);
                        $watchError = $message;
                    }

                    continue;
                }

                if ($current['runtime'] !== $fingerprints['runtime']) {
                    $stdout->fwrite("Runtime source changed.\n");
                    return self::RESTART_EXIT_CODE;
                }

                if ($current['publication'] === $fingerprints['publication']) {
                    continue;
                }

                try {
                    $rebuiltConfig = $this->rebuild($root);
                    if ($rebuiltConfig->basePath !== $config->basePath) {
                        $stdout->fwrite("Site deployment path changed.\n");
                        return self::RESTART_EXIT_CODE;
                    }
                    $stdout->fwrite("Rebuilt site.\n");
                } catch (ContentException $contentException) {
                    $this->errorReporter->warning(
                        $stderr,
                        'Build',
                        $contentException->getMessage() . ' Keeping the last valid site.',
                        $root,
                    );
                }

                $fingerprints = $current;
            }

            return $terminationSignal === null ? 0 : 128 + $terminationSignal;
        } finally {
            if (is_resource($process)) {
                $status = proc_get_status($process);
                if ($status['running']) {
                    proc_terminate($process);
                }

                proc_close($process);
            }

            if ($restoreSignalHandling instanceof Closure) {
                $restoreSignalHandling();
            }
        }
    }

    private function rebuild(string $root): Config
    {
        $inputs = $this->publicationInputLoader->load($root);
        $this->publisher->publish(
            $root,
            $inputs->config,
            $inputs->catalog,
            $inputs->limits,
            $inputs->templates,
            $inputs->assets,
            previewVersion: bin2hex(random_bytes(8)),
        );

        return $inputs->config;
    }

    /** @return resource */
    private function start(string $root, string $host, int $port, string $basePath): mixed
    {
        $command = [PHP_BINARY, '-S', $host . ':' . $port, '-t', $root . '/public', $root . '/resources/preview-router.php'];
        $environment = getenv();
        $environment['SNIPPET_BASE_PATH'] = $basePath;
        $process = $this->processStarter instanceof Closure
            ? ($this->processStarter)($command, $root, $environment)
            : @proc_open(
                $command,
                [
                    0 => ['file', 'php://stdin', 'r'],
                    1 => ['file', 'php://stdout', 'a'],
                    2 => ['file', 'php://stderr', 'a'],
                ],
                $pipes,
                $root,
                $environment,
            );

        if (!is_resource($process)) {
            throw new ContentException('Unable to start the PHP preview server.');
        }

        return $process;
    }

    /** @return array{publication: string, runtime: string} */
    private function fingerprints(string $root): array
    {
        $watchedFiles = [];
        $fingerprints = [
            'publication' => $this->fingerprint($root, self::PUBLICATION_WATCH_PATHS, $watchedFiles),
            'runtime' => $this->fingerprint($root, self::RUNTIME_WATCH_PATHS, $watchedFiles),
        ];

        $this->watchedFiles = $watchedFiles;

        return $fingerprints;
    }

    /**
     * @param list<string> $names
     * @param array<string, array{signature: string, hash: string}> $watchedFiles
     */
    private function fingerprint(string $root, array $names, array &$watchedFiles): string
    {
        $context = hash_init(self::FINGERPRINT_ALGORITHM);
        $first = true;
        foreach ($this->inventoryPaths($root, $names, $watchedFiles) as $record) {
            if (!$first) {
                hash_update($context, "\n");
            }

            hash_update($context, $record);
            $first = false;
        }

        return hash_final($context);
    }

    /**
     * @param list<string> $names
     * @param array<string, array{signature: string, hash: string}> $watchedFiles
     * @return Generator<int, string>
     */
    private function inventoryPaths(string $root, array $names, array &$watchedFiles): Generator
    {
        foreach ($names as $name) {
            $path = $root . '/' . $name;
            if (!is_dir($path)) {
                yield $name . ':missing';
                continue;
            }

            foreach ($this->inventory($path, $name, $watchedFiles) as $record) {
                yield $record;
            }
        }
    }

    /**
     * @param array<string, array{signature: string, hash: string}> $watchedFiles
     * @return Generator<int, string>
     */
    private function inventory(string $path, string $relative, array &$watchedFiles): Generator
    {
        $entries = @scandir($path, SCANDIR_SORT_ASCENDING);
        if ($entries === false) {
            throw new ContentException("Unable to watch directory '{$path}'.");
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . '/' . $entry;
            $childRelative = $relative . '/' . $entry;
            if (is_link($child)) {
                $target = readlink($child);
                // readlink() returns string|false, so comparing its result with true has the same concatenation result.
                // @pest-mutate-ignore: FalseToTrue
                yield $childRelative . ':link:' . ($target === false ? '' : $target);
            } elseif (is_dir($child)) {
                yield $childRelative . ':directory';
                foreach ($this->inventory($child, $childRelative, $watchedFiles) as $record) {
                    yield $record;
                }
            } elseif (is_file($child)) {
                $metadata = @stat($child);
                if ($metadata === false) {
                    throw new ContentException("Unable to inspect watched file '{$child}'.");
                }

                $signature = implode(':', [
                    (string) $metadata['dev'],
                    (string) $metadata['ino'],
                    (string) $metadata['size'],
                    (string) $metadata['mtime'],
                    (string) $metadata['ctime'],
                ]);
                $cached = $this->watchedFiles[$child] ?? null;
                $editableText = preg_match('/\.(?:css|html|js|md|php)$/Di', $child) === 1;
                if (!$editableText && $cached !== null && $cached['signature'] === $signature) {
                    $hash = $cached['hash'];
                } else {
                    $hash = @hash_file(self::FINGERPRINT_ALGORITHM, $child);
                    if ($hash === false) {
                        throw new ContentException("Unable to watch file '{$child}'.");
                    }
                }

                $watchedFiles[$child] = ['signature' => $signature, 'hash' => $hash];
                yield $childRelative . ':file:' . $hash;
            } else {
                yield $childRelative . ':other';
            }
        }
    }
}
