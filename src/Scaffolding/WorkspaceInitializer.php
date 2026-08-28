<?php

declare(strict_types=1);

namespace Snippet\Scaffolding;

use NoDiscard;
use RuntimeException;

/** Safely synchronizes bundled publication inputs into a workspace. */
final readonly class WorkspaceInitializer
{
    /** @var list<'content'|'site'|'resources'> */
    private const array INPUTS = ['content', 'site', 'resources'];

    /** @var list<string> */
    private const array EXCLUDED_FILES = ['resources/preview-router.php'];

    public function __construct(
        private string $scaffoldRoot,
        private string $workspace,
    ) {}

    /**
     * Create missing scaffold directories and files without replacing anything.
     *
     * @return array{created: list<string>, skipped: list<string>}
     *
     * @throws RuntimeException when either tree cannot be read or changed safely
     */
    #[NoDiscard('created and skipped paths describe the completed synchronization')]
    public function initialize(): array
    {
        if (!is_dir($this->workspace) || is_link($this->workspace) || !is_writable($this->workspace)) {
            throw new RuntimeException("Workspace '{$this->workspace}' must be a writable non-symlink directory.");
        }

        [$directories, $files] = $this->inventory();
        $this->preflight($directories, $files);

        foreach ($directories as $directory) {
            $destination = $this->workspace . '/' . $directory;
            if (!is_dir($destination) && !@mkdir($destination)) {
                throw new RuntimeException("Cannot create directory '{$directory}'.");
            }
        }

        $created = [];
        $skipped = [];
        foreach ($files as $file) {
            $destination = $this->workspace . '/' . $file;
            if (file_exists($destination)) {
                $skipped[] = $file;

                continue;
            }

            $this->copy($this->scaffoldRoot . '/' . $file, $destination, $file);
            $created[] = $file;
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /** @return array{list<string>, list<string>} */
    private function inventory(): array
    {
        $directories = [];
        $files = [];

        foreach (self::INPUTS as $input) {
            $this->inventoryDirectory($input, $directories, $files);
        }

        return [$directories, $files];
    }

    /**
     * @param list<string> $directories
     * @param list<string> $files
     */
    private function inventoryDirectory(string $relative, array &$directories, array &$files): void
    {
        $source = $this->scaffoldRoot . '/' . $relative;
        if (!is_dir($source) || is_link($source)) {
            throw new RuntimeException("Bundled scaffold directory '{$relative}' must be a non-symlink directory.");
        }

        $directories[] = $relative;
        $entries = @scandir($source);
        if ($entries === false) {
            throw new RuntimeException("Cannot read bundled scaffold directory '{$relative}'.");
        }

        foreach (array_diff($entries, ['.', '..']) as $entry) {
            $child = $relative . '/' . $entry;
            $childSource = $this->scaffoldRoot . '/' . $child;
            if (in_array($child, self::EXCLUDED_FILES, true)) {
                continue;
            }
            if (is_link($childSource)) {
                throw new RuntimeException("Bundled scaffold entry '{$child}' must not be a symbolic link.");
            }
            if (is_dir($childSource)) {
                $this->inventoryDirectory($child, $directories, $files);

                continue;
            }
            if (!is_file($childSource)) {
                throw new RuntimeException("Bundled scaffold entry '{$child}' must be a regular file.");
            }

            $files[] = $child;
        }
    }

    /**
     * @param list<string> $directories
     * @param list<string> $files
     */
    private function preflight(array $directories, array $files): void
    {
        foreach ($directories as $directory) {
            $destination = $this->workspace . '/' . $directory;
            if (is_link($destination)) {
                throw new RuntimeException("Cannot initialize '{$directory}': the destination is a symbolic link.");
            }
            if (file_exists($destination) && !is_dir($destination)) {
                throw new RuntimeException("Cannot initialize '{$directory}': the destination is not a directory.");
            }
        }

        foreach ($files as $file) {
            $destination = $this->workspace . '/' . $file;
            if (is_link($destination)) {
                throw new RuntimeException("Cannot initialize '{$file}': the destination is a symbolic link.");
            }
            if (file_exists($destination) && !is_file($destination)) {
                throw new RuntimeException("Cannot initialize '{$file}': the destination is not a regular file.");
            }
        }
    }

    private function copy(string $source, string $destination, string $relative): void
    {
        $input = @fopen($source, 'rb');
        if ($input === false) {
            throw new RuntimeException("Cannot read bundled scaffold file '{$relative}'.");
        }

        $output = @fopen($destination, 'xb');
        if ($output === false) {
            fclose($input);

            throw new RuntimeException("Cannot create file '{$relative}'.");
        }

        try {
            if (@stream_copy_to_stream($input, $output) === false) {
                throw new RuntimeException("Cannot copy scaffold file '{$relative}'.");
            }
        } catch (RuntimeException $runtimeException) {
            fclose($input);
            fclose($output);
            @unlink($destination);

            throw $runtimeException;
        }

        fclose($input);
        fclose($output);
    }
}
