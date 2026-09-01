<?php

declare(strict_types=1);

namespace Snippet\Authoring;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Snippet\Content\ContentType;
use Snippet\Exception\ContentException;
use Snippet\Support\Slug;

use function mb_ucfirst;

/**
 * Creates the minimal intentionally incomplete source files for one content item.
 *
 * The destination directory and both files are created exclusively. Failures
 * remove only paths created during the current attempt.
 */
final readonly class DraftCreator
{
    private const string PAGE_METADATA = "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n    'title' => '',\n    'description' => '',\n];\n";

    public function __construct(
        private DateTimeImmutable $clock = new DateTimeImmutable('now', new DateTimeZone('UTC')),
    ) {}

    /**
     * Create an incomplete draft and return its root-relative source path.
     *
     * @throws InvalidArgumentException when the type, slug, or date is invalid
     * @throws ContentException when the source tree cannot be changed safely
     */
    public function create(string $root, string $typeName, string $slug, ?string $date = null): string
    {
        $type = ContentType::tryFrom($typeName);
        if (!$type instanceof ContentType) {
            throw new InvalidArgumentException("New content type '{$typeName}' is invalid; use 'page' or 'article'.");
        }

        $this->validateSlug($slug);
        $collection = 'content/' . $type->collection();
        $sourceName = $type->sourceFilename();
        if ($type === ContentType::Page) {
            if ($date !== null) {
                throw new InvalidArgumentException('New page does not accept --date.');
            }

            $dateComponents = [];
            $metadata = self::PAGE_METADATA;
        } else {
            $selectedDate = $this->articleDate($date);
            $dateComponents = explode('-', $selectedDate);
            $metadata = $this->articleMetadata($selectedDate);
        }

        $this->requireCollection($root, $collection, $type);

        $createdDirectories = [];
        $createdFiles = [];

        try {
            $parent = $collection;
            foreach ($dateComponents as $component) {
                $parent .= '/' . $component;
                $this->ensureParentDirectory($root, $parent, $createdDirectories);
            }

            $destination = $parent . '/' . $slug;
            $this->createDestination($root, $destination, $createdDirectories);
            $this->writeFile($root, $destination . '/' . $sourceName, '', $createdFiles);
            $this->writeFile($root, $destination . '/meta.php', $metadata, $createdFiles);

            return $destination;
        } catch (ContentException $contentException) {
            $this->cleanUp($root, $createdFiles, $createdDirectories);
            throw $contentException;
        }
    }

    private function validateSlug(string $slug): void
    {
        if (!Slug::isCanonicalAscii($slug)) {
            throw new InvalidArgumentException("Invalid content slug '{$slug}'; use lowercase ASCII letters and numbers separated by single hyphens.");
        }

        if (Slug::isReservedContent($slug)) {
            throw new InvalidArgumentException("Content slug '{$slug}' is reserved because it collides with a site route.");
        }
    }

    private function articleDate(?string $date): string
    {
        $date ??= $this->clock->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d');
        if (!$this->isDate($date)) {
            throw new InvalidArgumentException("Article date '{$date}' must be a real date in YYYY-MM-DD format.");
        }

        return $date;
    }

    private function isDate(string $date): bool
    {
        if (preg_match('/^(?<year>\d{4})-(?<month>\d{2})-(?<day>\d{2})$/D', $date, $matches) !== 1) {
            return false;
        }

        return checkdate((int) $matches['month'], (int) $matches['day'], (int) $matches['year']);
    }

    private function requireCollection(string $root, string $collection, ContentType $type): void
    {
        $content = $root . '/content';
        $path = $root . '/' . $collection;
        $subject = mb_ucfirst($type->value, 'UTF-8');
        if (is_link($content) || !is_dir($path) || is_link($path)) {
            throw new ContentException("{$subject} collection directory '{$path}' does not exist or is not a regular non-symlink directory.");
        }
    }

    /** @param list<string> $createdDirectories */
    private function ensureParentDirectory(string $root, string $relative, array &$createdDirectories): void
    {
        $path = $root . '/' . $relative;
        if (is_dir($path) && !is_link($path)) {
            return;
        }

        if (file_exists($path) || is_link($path)) {
            throw new ContentException("Draft parent '{$relative}' must be a regular non-symlink directory.");
        }

        if (!@mkdir($path)) {
            throw new ContentException("Unable to create draft directory '{$relative}'.");
        }

        $createdDirectories[] = $relative;
    }

    /** @param list<string> $createdDirectories */
    private function createDestination(string $root, string $relative, array &$createdDirectories): void
    {
        $path = $root . '/' . $relative;
        if (file_exists($path) || is_link($path)) {
            throw new ContentException("Draft destination '{$relative}' already exists.");
        }

        if (!@mkdir($path)) {
            throw new ContentException("Unable to create draft directory '{$relative}'.");
        }

        $createdDirectories[] = $relative;
    }

    /** @param list<string> $createdFiles */
    private function writeFile(string $root, string $relative, string $contents, array &$createdFiles): void
    {
        $path = $root . '/' . $relative;
        $stream = @fopen($path, 'xb');
        if ($stream === false) {
            throw new ContentException("Unable to write draft file '{$relative}'.");
        }

        $createdFiles[] = $relative;
        try {
            if (@fwrite($stream, $contents) !== mb_strlen($contents, '8bit')) {
                throw new ContentException("Unable to write draft file '{$relative}'.");
            }

            if (!@fclose($stream)) {
                throw new ContentException("Unable to write draft file '{$relative}'.");
            }
        } finally {
            if (is_resource($stream)) {
                @fclose($stream);
            }
        }
    }

    private function articleMetadata(string $date): string
    {
        return "<?php\n\ndeclare(strict_types=1);\n\nreturn [\n    'title' => '',\n    'description' => '',\n    'date' => '{$date}',\n    'tags' => [],\n];\n";
    }

    /**
     * Remove only files and directories created by the failed attempt.
     *
     * @param list<string> $createdFiles
     * @param list<string> $createdDirectories
     */
    private function cleanUp(string $root, array $createdFiles, array $createdDirectories): void
    {
        foreach ($createdFiles as $file) {
            @unlink($root . '/' . $file);
        }

        foreach (array_reverse($createdDirectories) as $directory) {
            @rmdir($root . '/' . $directory);
        }
    }
}
