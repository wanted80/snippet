<?php

declare(strict_types=1);

namespace Snippet\Publishing;

/** Immutable counts and optional backup-cleanup diagnostics for a successfully promoted publication. */
final class BuildReport
{
    public int $files {
        get => $this->documents + $this->assets;
    }

    public function __construct(
        public readonly int $articles,
        public readonly int $pages,
        public readonly int $tags,
        public readonly int $assets,
        public readonly int $documents,
        public readonly ?string $cleanupWarning = null,
    ) {}
}
