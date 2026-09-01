<?php

declare(strict_types=1);

namespace Snippet\Publishing;

use LogicException;

/** One retained entry asset whose public name identifies its exact output bytes. */
final readonly class PublicationAsset
{
    public string $publishedPath;

    /** @throws LogicException when the logical path has no filename extension */
    public function __construct(
        public string $logicalPath,
        public string $contents,
    ) {
        $extensionOffset = mb_strrpos($logicalPath, '.', 0, '8bit');
        if (!is_int($extensionOffset)) {
            throw new LogicException("Publication asset path '{$logicalPath}' must have an extension.");
        }

        $this->publishedPath = mb_substr($logicalPath, 0, $extensionOffset, '8bit')
            . '.' . hash('xxh3', $contents)
            . mb_substr($logicalPath, $extensionOffset, null, '8bit');
    }

    public function bytes(): int
    {
        return mb_strlen($this->contents, '8bit');
    }
}
