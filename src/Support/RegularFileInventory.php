<?php

declare(strict_types=1);

namespace Snippet\Support;

use NoDiscard;
use Snippet\Exception\ContentException;

/** Inventories a deterministic tree containing only directories and regular files. */
final readonly class RegularFileInventory
{
    /**
     * @return list<string> file paths relative to the given root in lexical order
     *
     * @throws ContentException when the tree is unreadable or contains a symlink or special entry
     */
    #[NoDiscard('the deterministic file inventory should be consumed')]
    public function files(string $root, string $subject): array
    {
        if (!is_dir($root) || is_link($root)) {
            throw new ContentException(mb_ucfirst($subject, 'UTF-8') . " directory '{$root}' must be a regular non-symlink directory.");
        }

        $files = [];
        $this->inventory($root, $subject, '', $files);

        return $files;
    }

    /** @param list<string> $files */
    private function inventory(string $root, string $subject, string $relative, array &$files): void
    {
        $directory = $relative === '' ? $root : $root . '/' . $relative;
        $entries = @scandir($directory, SCANDIR_SORT_ASCENDING);
        if ($entries === false) {
            throw new ContentException("Unable to read {$subject} directory '{$directory}'.");
        }

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $item = $relative === '' ? $entry : $relative . '/' . $entry;
            $path = $root . '/' . $item;
            if (is_link($path)) {
                throw new ContentException(mb_ucfirst($subject, 'UTF-8') . " contains forbidden symlink '{$item}'.");
            }

            if (is_dir($path)) {
                $this->inventory($root, $subject, $item, $files);
                continue;
            }

            if (!is_file($path)) {
                throw new ContentException(mb_ucfirst($subject, 'UTF-8') . " contains unsupported filesystem entry '{$item}'.");
            }

            $files[] = $item;
        }
    }
}
