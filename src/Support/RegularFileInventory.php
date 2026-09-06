<?php

declare(strict_types=1);

namespace Snippet\Support;

use NoDiscard;
use Snippet\Exception\ContentException;

/** Inventories regular files with bounded directory reads and recursion. */
final readonly class RegularFileInventory
{
    /**
     * Every directory entry consumes the traversal budget, including empty
     * directories. maximumFiles × maximumDepth allows the complete parent chain
     * of every permitted file without allowing unlimited empty subtrees.
     *
     * @return list<string> relative file paths in deterministic depth-first lexical order
     *
     * @throws ContentException when the tree is unreadable, exceeds a ceiling, or contains a symlink or special entry
     */
    #[NoDiscard('the deterministic file inventory should be consumed')]
    public function files(string $root, string $subject, int $maximumFiles, int $maximumDepth): array
    {
        if (!is_dir($root) || is_link($root)) {
            throw new ContentException(mb_ucfirst($subject, 'UTF-8') . " directory '{$root}' must be a regular non-symlink directory.");
        }

        $files = [];
        $visitedEntries = 0;
        $this->inventory($root, $subject, '', 0, $maximumFiles, $maximumDepth, $visitedEntries, $files);

        return $files;
    }

    /** @param list<string> $files */
    private function inventory(
        string $root,
        string $subject,
        string $relative,
        int $depth,
        int $maximumFiles,
        int $maximumDepth,
        int &$visitedEntries,
        array &$files,
    ): void {
        $directory = $relative === '' ? $root : $root . '/' . $relative;
        foreach ($this->entries($directory, $subject, $maximumFiles * $maximumDepth, $visitedEntries) as $entry) {
            $item = $relative === '' ? $entry : $relative . '/' . $entry;
            if ($depth + 1 > $maximumDepth) {
                throw new ContentException(mb_ucfirst($subject, 'UTF-8') . " entry '{$item}' exceeds directory depth {$maximumDepth}.");
            }

            $path = $root . '/' . $item;
            if (is_link($path)) {
                throw new ContentException(mb_ucfirst($subject, 'UTF-8') . " contains forbidden symlink '{$item}'.");
            }

            if (is_dir($path)) {
                $this->inventory($root, $subject, $item, $depth + 1, $maximumFiles, $maximumDepth, $visitedEntries, $files);
                continue;
            }

            if (!is_file($path)) {
                throw new ContentException(mb_ucfirst($subject, 'UTF-8') . " contains unsupported filesystem entry '{$item}'.");
            }

            if (count($files) >= $maximumFiles) {
                throw new ContentException(mb_ucfirst($subject, 'UTF-8') . " exceeds the {$maximumFiles}-file limit while adding '{$item}'.");
            }

            $files[] = $item;
        }
    }

    /** @return list<string> */
    private function entries(string $directory, string $subject, int $maximumEntries, int &$visitedEntries): array
    {
        $stream = @opendir($directory);
        if ($stream === false) {
            throw new ContentException("Unable to read {$subject} directory '{$directory}'.");
        }

        $entries = [];
        try {
            while (($entry = readdir($stream)) !== false) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                if (++$visitedEntries > $maximumEntries) {
                    throw new ContentException(mb_ucfirst($subject, 'UTF-8') . " exceeds the {$maximumEntries}-entry traversal limit in '{$directory}'.");
                }

                $entries[] = $entry;
            }
        } finally {
            closedir($stream);
        }

        sort($entries, SORT_STRING);

        return $entries;
    }
}
