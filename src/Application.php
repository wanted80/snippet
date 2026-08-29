<?php

declare(strict_types=1);

namespace Snippet;

use Closure;
use InvalidArgumentException;
use Snippet\Authoring\DraftCreator;
use Snippet\Content\ContentType;
use Snippet\Exception\ContentException;
use Snippet\Preview\Previewer;
use Snippet\Preview\PreviewServer;
use Snippet\Publishing\BuildReport;
use Snippet\Publishing\PublicationInputLoader;
use Snippet\Publishing\Publisher;
use Snippet\Support\ApplicationVersion;
use SplFileObject;

use function count;
use function filter_var;

/** Runs the command-line validation interface for a project root. */
final readonly class Application
{
    private const string USAGE = "Usage:\n  bin/snippet --version\n  bin/snippet validate\n  bin/snippet build\n  bin/snippet preview [--host=<host>] [--port=<port>]\n  bin/snippet new page <slug>\n  bin/snippet new article <slug> [--date=YYYY-MM-DD]\n";

    public function __construct(
        private string $root,
        private Publisher $publisher = new Publisher(),
        private ?Previewer $previewer = null,
        private PublicationInputLoader $publicationInputLoader = new PublicationInputLoader(),
        private ?DraftCreator $draftCreator = null,
        /** @var (Closure(): int)|null */
        private ?Closure $nanoseconds = null,
        private string $usage = self::USAGE,
    ) {}

    /**
     * Validate CLI input and report either a catalog summary or a content error.
     *
     * @param list<string> $arguments
     */
    public function run(array $arguments, SplFileObject $stdout, SplFileObject $stderr): int
    {
        if (count($arguments) < 2 || !in_array($arguments[1], ['--version', 'validate', 'build', 'preview', 'new'], true)) {
            $stderr->fwrite($this->usage);
            return 2;
        }

        $command = $arguments[1];
        if ($command === '--version') {
            if (count($arguments) !== 2) {
                $stderr->fwrite($this->usage);
                return 2;
            }

            $stdout->fwrite('Snippet ' . ApplicationVersion::CURRENT . "\n");
            return 0;
        }

        if ($command === 'new') {
            return $this->newDraft(array_slice($arguments, 2), $stdout, $stderr);
        }

        if ($command !== 'preview' && count($arguments) !== 2) {
            $stderr->fwrite($this->usage);
            return 2;
        }

        $previewAddress = $this->previewAddress(array_slice($arguments, 2), $stderr);
        if ($previewAddress === null) {
            return 2;
        }

        $started = $command === 'build' ? $this->nanoseconds() : null;
        $report = null;
        try {
            if ($command === 'preview') {
                $previewer = $this->previewer ?? new PreviewServer();
                return $previewer->run($this->root, $stdout, $stderr, ...$previewAddress);
            }

            $inputs = $this->publicationInputLoader->load($this->root);
            $catalog = $inputs->catalog;
            if ($command === 'build') {
                $report = $this->publisher->publish($this->root, $inputs->config, $catalog, $inputs->limits, $inputs->templates);
            }
        } catch (ContentException $contentException) {
            $stderr->fwrite('Error: ' . $contentException->getMessage() . "\n");
            return 1;
        }

        if ($report instanceof BuildReport && is_int($started)) {
            $milliseconds = intdiv($this->nanoseconds() - $started + 500_000, 1_000_000);
            $stdout->fwrite('Built site: '
                . $this->plural($report->articles, 'article') . ', '
                . $this->plural($report->pages, 'page') . ', '
                . $this->plural($report->tags, 'tag') . ', '
                . $this->plural($report->assets, 'asset') . ', '
                . $this->plural($report->files, 'file') . " in {$milliseconds} ms.\n");
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
    private function newDraft(array $arguments, SplFileObject $stdout, SplFileObject $stderr): int
    {
        $parsed = $this->newDraftArguments($arguments, $stderr);
        if ($parsed === null) {
            return 2;
        }

        [$type, $slug, $date] = $parsed;
        try {
            $draftCreator = $this->draftCreator ?? new DraftCreator();
            $destination = $draftCreator->create($this->root, $type, $slug, $date);
        } catch (InvalidArgumentException $invalidArgumentException) {
            $this->usageError($stderr, $invalidArgumentException->getMessage());
            return 2;
        } catch (ContentException $contentException) {
            $stderr->fwrite('Error: ' . $contentException->getMessage() . "\n");
            return 1;
        }

        $source = ContentType::from($type)->sourceFilename();
        $stdout->fwrite("Created incomplete draft: {$destination}\n");
        $stdout->fwrite("Complete {$destination}/{$source} and {$destination}/meta.php before validating or building.\n");
        return 0;
    }

    /**
     * @param list<string> $arguments
     * @return array{string, string, ?string}|null
     */
    private function newDraftArguments(array $arguments, SplFileObject $stderr): ?array
    {
        if (count($arguments) < 2) {
            return $this->usageError($stderr, 'New command requires a content type and slug.');
        }

        [$type, $slug] = $arguments;
        if (str_starts_with($slug, '--')) {
            return $this->usageError($stderr, 'New command requires the slug before any options.');
        }

        $date = null;
        $dateProvided = false;
        foreach (array_slice($arguments, 2) as $argument) {
            if (str_starts_with($argument, '--date=')) {
                if ($dateProvided) {
                    return $this->usageError($stderr, 'New article option --date may be provided only once.');
                }

                $dateProvided = true;
                $date = mb_substr($argument, 7);
                continue;
            }

            if (str_starts_with($argument, '--date')) {
                return $this->usageError($stderr, 'Article date option must use --date=YYYY-MM-DD.');
            }

            if (str_starts_with($argument, '--')) {
                return $this->usageError($stderr, "Unknown new option '{$argument}'.");
            }

            return $this->usageError($stderr, "Unexpected new argument '{$argument}'.");
        }

        return [$type, $slug, $date];
    }

    /**
     * @param list<string> $options
     * @return array{string, int}|null
     */
    private function previewAddress(array $options, SplFileObject $stderr): ?array
    {
        $host = '127.0.0.1';
        $port = 8080;
        $hostProvided = false;
        $portProvided = false;

        foreach ($options as $option) {
            if (str_starts_with($option, '--host=')) {
                if ($hostProvided) {
                    return $this->optionError($stderr, 'Preview option --host may be provided only once.');
                }

                $hostProvided = true;
                $host = mb_substr($option, 7);
                if (!$this->validHost($host)) {
                    return $this->optionError($stderr, 'Preview host must be a valid IP address or hostname.');
                }

                continue;
            }

            if (str_starts_with($option, '--port=')) {
                if ($portProvided) {
                    return $this->optionError($stderr, 'Preview option --port may be provided only once.');
                }

                $portProvided = true;
                $value = mb_substr($option, 7);
                if (preg_match('/^[0-9]+$/D', $value) !== 1 || (int) $value < 1 || (int) $value > 65_535) {
                    return $this->optionError($stderr, 'Preview port must be an integer from 1 through 65535.');
                }

                $port = (int) $value;
                continue;
            }

            return $this->optionError($stderr, "Unknown preview option '{$option}'.");
        }

        return [$host, $port];
    }

    private function validHost(string $host): bool
    {
        return filter_var($host, FILTER_VALIDATE_IP) !== false
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    private function optionError(SplFileObject $stderr, string $message): null
    {
        $stderr->fwrite("Error: {$message}\n" . $this->usage);
        return null;
    }

    private function usageError(SplFileObject $stderr, string $message): null
    {
        $stderr->fwrite("Error: {$message}\n" . $this->usage);
        return null;
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
