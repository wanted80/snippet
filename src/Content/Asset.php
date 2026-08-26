<?php

declare(strict_types=1);

namespace Snippet\Content;

/** A regular asset file identified by its path relative to a content item. */
final readonly class Asset
{
    public function __construct(public string $path) {}
}
