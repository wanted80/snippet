<?php

declare(strict_types=1);

namespace Snippet;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use Snippet\Authoring\DraftCreator;
use Snippet\Cli\Command;
use Snippet\Cli\ErrorReporter;
use Snippet\Cli\Output;
use Snippet\Cli\OutputOptions;
use Snippet\Content\ContentType;
use Snippet\Exception\ContentException;
use Snippet\Inspection\Inspector;
use Snippet\Preview\Previewer;
use Snippet\Preview\PreviewServer;
use Snippet\Publishing\BuildReport;
use Snippet\Publishing\PublicationInputLoader;
use Snippet\Publishing\Publisher;
use Snippet\Scaffolding\WorkspaceInitializer;
use Snippet\Support\ApplicationVersion;
use SplFileObject;

use function count;
use function filter_var;

/** Validates and dispatches every engine CLI command for a project root. */
final readonly class Application
{
    private const int FAILURE = 1;

    private const int INVALID_USAGE = 2;

    private const string USAGE = "Usage:\n  bin/snippet --version [--json]\n  bin/snippet inspect <capabilities|theme|config|content> --json\n  bin/snippet validate [--json]\n  bin/snippet build [--json]\n  bin/snippet preview [--host=<host>] [--port=<port>]\n  bin/snippet new page <slug> [--json]\n  bin/snippet new article <slug> [--date=YYYY-MM-DD] [--json]\n";

    public function __construct(
        /** A missing current directory still permits installed-engine inspection and version reporting. */
        private ?string $root,
        private ?Publisher $publisher = null,
        private ?Previewer $previewer = null,
        private ?PublicationInputLoader $publicationInputLoader = null,
        private ?DraftCreator $draftCreator = null,
        /** @var (Closure(): int)|null */
        private ?Closure $nanoseconds = null,
        private string $usage = self::USAGE,
        private ErrorReporter $errorReporter = new ErrorReporter(),
        private bool $previewEnabled = true,
        private ?WorkspaceInitializer $initializer = null,
        private string $engineRoot = __DIR__ . '/..',
    ) {}

    /**
     * Validate CLI input and report either a catalog summary or a content error.
     *
     * @param list<string> $arguments
     */
    public function run(array $arguments, SplFileObject $stdout, SplFileObject $stderr): int
    {
        $options = new OutputOptions($arguments);
        $output = new Output($options->json, $stdout, $stderr, $this->errorReporter, $this->usage, $this->root);
        try {
            $arguments = $options->arguments();
        } catch (InvalidArgumentException $invalidArgumentException) {
            $output->usageError($invalidArgumentException->getMessage());
            return self::INVALID_USAGE;
        }

        if (count($arguments) < 2) {
            $output->usageError('A command is required.');
            return self::INVALID_USAGE;
        }

        $command = Command::tryFrom($arguments[1]);
        if ($command === null || ($command === Command::Preview && !$this->previewEnabled) || ($command === Command::Init && !$this->initializer instanceof WorkspaceInitializer)) {
            $output->usageError("Unknown command '{$arguments[1]}'.");
            return self::INVALID_USAGE;
        }

        if (!$command->acceptsArguments() && count($arguments) !== 2) {
            $output->usageError("Command '{$command->value}' does not accept arguments.");
            return self::INVALID_USAGE;
        }

        if ($command === Command::Version) {
            if ($output->json) {
                $output->result([]);
            } else {
                $stdout->fwrite('Snippet ' . ApplicationVersion::CURRENT . "\n");
            }
            return 0;
        }

        if ($command === Command::Init) {
            return $this->initialize($this->initializer, $stdout, $output);
        }

        if ($command === Command::Inspect) {
            return $this->inspect(array_slice($arguments, 2), $output);
        }

        if ($command === Command::NewContent) {
            return $this->newDraft(array_slice($arguments, 2), $stdout, $output);
        }

        if ($command === Command::Preview) {
            $previewAddress = $this->previewAddress(array_slice($arguments, 2), $output);
            if ($previewAddress === null) {
                return self::INVALID_USAGE;
            }
        }

        $started = $command === Command::Build && !$output->json ? $this->nanoseconds() : null;
        $report = null;
        try {
            $root = $this->workspaceRoot();
            if ($command === Command::Preview) {
                $previewer = $this->previewer ?? new PreviewServer(errorReporter: $this->errorReporter);
                return $previewer->run($root, $stdout, $stderr, ...$previewAddress);
            }

            $publisher = $this->publisher ?? new Publisher();
            $publicationInputLoader = $this->publicationInputLoader ?? new PublicationInputLoader(publisher: $publisher);
            $inputs = $publicationInputLoader->load($root);
            $catalog = $inputs->catalog;
            if ($command === Command::Build) {
                $report = $publisher->publish($root, $inputs->config, $catalog, $inputs->limits, $inputs->templates, $inputs->assets);
            }
        } catch (ContentException $contentException) {
            $output->failure($command, $contentException->getMessage());
            return self::FAILURE;
        }

        if ($output->json) {
            if ($report instanceof BuildReport) {
                $result = [
                    'command' => 'build',
                    'output' => 'public/',
                    'counts' => ['articles' => $report->articles, 'pages' => $report->pages, 'tags' => $report->tags, 'assets' => $report->assets, 'files' => $report->files],
                ];
                if ($report->cleanupWarning !== null) {
                    $result['warnings'] = [$report->cleanupWarning];
                }
                $output->result($result);
            } else {
                $output->result([
                    'command' => 'validate',
                    'valid' => true,
                    'counts' => ['articles' => count($catalog->articles), 'pages' => count($catalog->pages), 'tags' => count($catalog->tags()), 'assets' => $inputs->assetCount()],
                ]);
            }
            return 0;
        }

        if ($report instanceof BuildReport && is_int($started)) { // @pest-mutate-ignore: BooleanAndToBooleanOr,InstanceOfToTrue
            $milliseconds = intdiv($this->nanoseconds() - $started + 500_000, 1_000_000);
            $stdout->fwrite('Built site: '
                . $this->plural($report->articles, 'article') . ', '
                . $this->plural($report->pages, 'page') . ', '
                . $this->plural($report->tags, 'tag') . ', '
                . $this->plural($report->assets, 'asset') . ', '
                . $this->plural($report->files, 'file') . " in {$milliseconds} ms.\n");
            if ($report->cleanupWarning !== null) {
                $this->errorReporter->warning($stderr, 'Publication cleanup', $report->cleanupWarning, $this->root);
            }
        } else {
            $stdout->fwrite('Valid site: '
                . $this->plural(count($catalog->articles), 'article') . ', '
                . $this->plural(count($catalog->pages), 'page') . ', '
                . $this->plural(count($catalog->tags()), 'tag') . ', '
                . $this->plural($inputs->assetCount(), 'asset') . ".\n");
        }
        return 0;
    }

    /**
     * @param list<string> $arguments
     */
    private function newDraft(array $arguments, SplFileObject $stdout, Output $output): int
    {
        $parsed = $this->newDraftArguments($arguments, $output);
        if ($parsed === null) {
            return self::INVALID_USAGE;
        }

        [$typeName, $slug, $date] = $parsed;
        try {
            $type = ContentType::tryFrom($typeName);
            if ($type === null) {
                throw new InvalidArgumentException("New content type '{$typeName}' is invalid; use 'page' or 'article'.");
            }

            $draftCreator = $this->draftCreator ?? new DraftCreator();
            $destination = $draftCreator->create($this->workspaceRoot(), $type, $slug, $date);
        } catch (InvalidArgumentException $invalidArgumentException) {
            $output->usageError($invalidArgumentException->getMessage());
            return self::INVALID_USAGE;
        } catch (ContentException $contentException) {
            $output->failure(Command::NewContent, $contentException->getMessage());
            return self::FAILURE;
        }

        $source = $type->sourceFilename();
        if ($output->json) {
            $output->result(['command' => 'new', 'created' => ["{$destination}/{$source}", "{$destination}/meta.php"], 'incomplete' => true]);
            return 0;
        }
        $stdout->fwrite("Created incomplete draft: {$destination}\n");
        $stdout->fwrite("Complete {$destination}/{$source} and {$destination}/meta.php before validating or building.\n");
        return 0;
    }

    /** @throws ContentException when the entrypoint could not resolve its working directory */
    private function workspaceRoot(): string
    {
        if ($this->root === null) {
            throw new ContentException('Unable to resolve the current workspace.');
        }
        return $this->root;
    }

    /**
     * @param list<string> $arguments
     * @return array{string, string, ?string}|null
     */
    private function newDraftArguments(array $arguments, Output $output): ?array
    {
        if (count($arguments) < 2) {
            return $output->usageError('New command requires a content type and slug.');
        }

        [$type, $slug] = $arguments;
        if (str_starts_with($slug, '--')) {
            return $output->usageError('New command requires the slug before any options.');
        }

        $date = null;
        $dateProvided = false;
        foreach (array_slice($arguments, 2) as $argument) {
            if (str_starts_with($argument, '--date=')) {
                if ($dateProvided) {
                    return $output->usageError('New article option --date may be provided only once.');
                }

                $dateProvided = true;
                $date = mb_substr($argument, 7, null, '8bit');
                continue;
            }

            if (str_starts_with($argument, '--date')) {
                return $output->usageError('Article date option must use --date=YYYY-MM-DD.');
            }

            if (str_starts_with($argument, '--')) {
                return $output->usageError("Unknown new option '{$argument}'.");
            }

            return $output->usageError("Unexpected new argument '{$argument}'.");
        }

        return [$type, $slug, $date];
    }

    /**
     * @param list<string> $options
     * @return array{string, int}|null
     */
    private function previewAddress(array $options, Output $output): ?array
    {
        $host = '127.0.0.1';
        $port = 8080;
        $hostProvided = false;
        $portProvided = false;

        foreach ($options as $option) {
            if (str_starts_with($option, '--host=')) {
                if ($hostProvided) {
                    return $output->usageError('Preview option --host may be provided only once.');
                }

                $hostProvided = true;
                $host = mb_substr($option, 7, null, '8bit');
                if (!$this->validHost($host)) {
                    return $output->usageError('Preview host must be a valid IP address or hostname.');
                }

                continue;
            }

            if (str_starts_with($option, '--port=')) {
                if ($portProvided) {
                    return $output->usageError('Preview option --port may be provided only once.');
                }

                $portProvided = true;
                $value = mb_substr($option, 7, null, '8bit');
                if (preg_match('/^[0-9]+$/D', $value) !== 1) {
                    return $output->usageError('Preview port must be an integer from 1 through 65535.');
                }

                $port = (int) $value;
                if ($port < 1 || $port > 65_535) {
                    return $output->usageError('Preview port must be an integer from 1 through 65535.');
                }

                continue;
            }

            if (str_starts_with($option, '--')) {
                return $output->usageError("Unknown preview option '{$option}'.");
            }

            return $output->usageError("Unexpected preview argument '{$option}'.");
        }

        return [$host, $port];
    }

    private function validHost(string $host): bool
    {
        return filter_var($host, FILTER_VALIDATE_IP) !== false
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    /** @param list<string> $arguments */
    private function inspect(array $arguments, Output $output): int
    {
        if (!$output->json || count($arguments) !== 1 || !in_array($arguments[0], Inspector::SUBJECTS, true)) {
            $output->usageError('Inspect requires capabilities, theme, config, or content followed by --json.');
            return self::INVALID_USAGE;
        }
        try {
            $result = new Inspector($this->engineRoot)->inspect($arguments[0], $this->initializer instanceof WorkspaceInitializer, $this->previewEnabled);
        } catch (ContentException $contentException) {
            $output->failure(Command::Inspect, $contentException->getMessage());
            return self::FAILURE;
        }
        $output->result(['command' => 'inspect', 'subject' => $arguments[0], ...$result]);
        return 0;
    }

    private function initialize(WorkspaceInitializer $initializer, SplFileObject $stdout, Output $output): int
    {
        try {
            $result = $initializer->initialize();
        } catch (RuntimeException $runtimeException) {
            $output->failure(Command::Init, $runtimeException->getMessage());
            return self::FAILURE;
        }
        if ($output->json) {
            $output->result(['command' => 'init', ...$result]);
            return 0;
        }
        if ($result['created'] === []) {
            $stdout->fwrite("Snippet workspace is already initialized.\nNo files were changed.\n");
            return 0;
        }
        $stdout->fwrite("Initializing Snippet workspace.\n\n");
        foreach ($result['created'] as $file) {
            $stdout->fwrite("Created: {$file}\n");
        }
        foreach ($result['skipped'] as $file) {
            $stdout->fwrite("Skipped: {$file}\n");
        }
        $stdout->fwrite("\nWorkspace initialized.\nExisting files were not overwritten.\n");
        return 0;
    }

    private function plural(int $count, string $word): string
    {
        return $count . ' ' . $word . ($count === 1 ? '' : 's');
    }

    private function nanoseconds(): int
    {
        if ($this->nanoseconds instanceof Closure) {
            return ($this->nanoseconds)();
        }

        return (int) hrtime(true);
    }
}
